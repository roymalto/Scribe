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

    protected function getHeaderActions(): array
    {
        $p = fn (): Prescription => $this->record;

        return [
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
            Action::make('pdf')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $p()->isIssued())
                ->url(fn () => route('prescriptions.pdf', $p()), shouldOpenInNewTab: true),
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
