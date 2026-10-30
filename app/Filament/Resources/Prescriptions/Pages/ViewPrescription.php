<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use App\Services\Prescribing;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Js;

// A prescription and what can be done with it next: issue (its doctor), cancel, correct, repeat, download the PDF
class ViewPrescription extends ViewRecord
{
    protected static string $resource = PrescriptionResource::class;

    public function getTitle(): string
    {
        return $this->record->reference ?? 'Draft prescription';
    }

    private function myRole(): ?string
    {
        return auth()->user()?->roleIn(Filament::getTenant());
    }

    // The new draft is for the signed-in doctor if they are one here, otherwise the original's doctor
    private function draftDoctor(): ?int
    {
        return $this->myRole() === 'DOCTOR' && ! auth()->user()->is_admin ? auth()->id() : null;
    }

    // A PDF button. On a computer it downloads the file. On a phone or tablet it opens the phone's share sheet with the
    // PDF (Viber, Messenger, save to Files, print): a download there opens a bare viewer with no way to share or go
    // back (iPhone, home-screen app). The share sheet must open straight from the tap, so the PDF is fetched when the
    // page opens. It needs https (the share sheet isn't offered on plain http); without it, the button downloads.
    protected static function pdfAction(Action $action, \Closure $p, bool $unsigned): Action
    {
        $url = fn () => route('prescriptions.pdf', array_filter(['prescription' => $p(), 'unsigned' => $unsigned ? 1 : null]));
        $name = fn () => $p()->reference.($unsigned ? '-to-sign' : '').'.pdf';

        // Single quotes only in the script: Filament doesn't escape double quotes in these attributes, and it drops
        // x-on:click on a link, so the click handler is added from x-init
        return $action->url($url)->extraAttributes(fn () => [
            'download' => '',
            'x-data' => '{ pdfFile: null }',
            'x-init' => "if (window.matchMedia('(pointer: coarse)').matches && navigator.canShare) {"
                .' fetch('.Js::from($url()).", { credentials: 'same-origin' })"
                .' .then(r => r.ok ? r.blob() : null)'
                .' .then(b => { if (b) pdfFile = new File([b], '.Js::from($name()).", { type: 'application/pdf' }) });"
                ." \$el.addEventListener('click', e => { if (pdfFile && navigator.canShare({ files: [pdfFile] })) {"
                .' e.preventDefault(); navigator.share({ files: [pdfFile], title: '.Js::from($p()->reference).' }).catch(() => {}) } }) }',
        ]);
    }

    protected function getHeaderActions(): array
    {
        $p = fn (): Prescription => $this->record;

        return [
            // the way out on a phone, where the breadcrumbs are hidden
            Action::make('back')->label('Back to prescriptions')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn () => PrescriptionResource::getUrl('index')),
            Action::make('issue')->label('Issue')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $p()->isDraft() && $p()->doctor_user_id === auth()->id())
                ->requiresConfirmation()->modalHeading('Issue this prescription?')
                ->modalDescription(function () use ($p) {
                    $w = Prescribing::allergyWarnings($p()->patient, $p()->items->pluck('generic_name'));

                    return ($w ? 'Allergy warning: '.implode('; ', $w).'. ' : '')
                        .'Once issued it cannot be changed: corrections make a new prescription.';
                })
                ->action(function () use ($p) {
                    if ($error = app(Prescribing::class)->issue($p())) {
                        Notification::make()->danger()->title('Not issued')->body($error)->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Issued '.$p()->reference)->send();
                    $this->refreshFormData(['status', 'reference', 'issued_at']);
                }),
            static::pdfAction(Action::make('pdf')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $p()->isIssued()), $p, unsigned: false),
            static::pdfAction(Action::make('pdfToSign')->label('PDF to sign by hand')->icon('heroicon-o-pencil')->color('gray')
                ->visible(fn () => $p()->isIssued() && filled($p()->snapshot['doctor']['signature_path'] ?? null))
                ->tooltip('The same prescription without the signature image, to print and sign'), $p, unsigned: true),
            EditAction::make()->label('Edit draft')->visible(fn () => $p()->isDraft()),
            ActionGroup::make([
                Action::make('repeat')->label('Repeat')->icon('heroicon-o-arrow-path')
                    ->visible(fn () => ! $p()->isDraft())
                    ->requiresConfirmation()->modalDescription('A new draft with the same medicines, for the patient\'s next supply.')
                    ->action(fn () => $this->redirect(PrescriptionResource::getUrl('edit', [
                        'record' => app(Prescribing::class)->copyAsDraft($p(), corrects: false, doctorId: $this->draftDoctor())]))),
                Action::make('correct')->label('Correct')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => $p()->isIssued())
                    ->requiresConfirmation()
                    ->modalDescription('A new draft copied from this one. When it is issued, it replaces this prescription (marked superseded).')
                    ->action(fn () => $this->redirect(PrescriptionResource::getUrl('edit', [
                        'record' => app(Prescribing::class)->copyAsDraft($p(), corrects: true, doctorId: $this->draftDoctor())]))),
                Action::make('cancel')->label('Cancel prescription')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn () => $p()->isIssued() && ($p()->doctor_user_id === auth()->id() || $this->myRole() === 'CLINIC_ADMIN'))
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(function (array $data) use ($p) {
                        if ($error = app(Prescribing::class)->cancel($p(), $data['reason'])) {
                            Notification::make()->danger()->title('Not cancelled')->body($error)->send();

                            return;
                        }
                        Notification::make()->success()->title('Prescription cancelled')->send();
                        $this->refreshFormData(['status']);
                    }),
                DeleteAction::make()->label('Delete draft')->visible(fn () => $p()->isDraft()),
            ])->label('More')->button()->color('gray'),
        ];
    }
}
