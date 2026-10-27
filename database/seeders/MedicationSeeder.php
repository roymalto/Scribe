<?php

namespace Database\Seeders;

use App\Models\Medication;
use Illuminate\Database\Seeder;

// The shared medicine catalogue: about 100 common outpatient generics with usual strengths and forms, so doctors can
// pick instead of typing. Loaded once (when there are no shared medicines yet); administrators keep it afterwards and
// clinics add their own. Dangerous-drug flags cover the obvious ones only: check this list against the Philippine
// National Formulary and the PDEA schedules before go-live (phase 4).
class MedicationSeeder extends Seeder
{
    // generic, strength, dosage form, route, dangerous drug
    private const ITEMS = [
        // antibiotics
        ['Amoxicillin', '500 mg', 'capsule', 'oral'], ['Amoxicillin', '250 mg/5 mL', 'suspension', 'oral'],
        ['Co-amoxiclav', '625 mg', 'tablet', 'oral'], ['Co-amoxiclav', '312.5 mg/5 mL', 'suspension', 'oral'],
        ['Cefalexin', '500 mg', 'capsule', 'oral'], ['Cefuroxime', '500 mg', 'tablet', 'oral'],
        ['Cefixime', '200 mg', 'capsule', 'oral'], ['Azithromycin', '500 mg', 'tablet', 'oral'],
        ['Azithromycin', '200 mg/5 mL', 'suspension', 'oral'], ['Clarithromycin', '500 mg', 'tablet', 'oral'],
        ['Ciprofloxacin', '500 mg', 'tablet', 'oral'], ['Levofloxacin', '500 mg', 'tablet', 'oral'],
        ['Doxycycline', '100 mg', 'capsule', 'oral'], ['Cotrimoxazole', '800 mg/160 mg', 'tablet', 'oral'],
        ['Metronidazole', '500 mg', 'tablet', 'oral'], ['Nitrofurantoin', '100 mg', 'capsule', 'oral'],
        ['Clindamycin', '300 mg', 'capsule', 'oral'], ['Cloxacillin', '500 mg', 'capsule', 'oral'],
        // pain and fever
        ['Paracetamol', '500 mg', 'tablet', 'oral'], ['Paracetamol', '250 mg/5 mL', 'suspension', 'oral'],
        ['Paracetamol', '120 mg/5 mL', 'syrup', 'oral'], ['Ibuprofen', '400 mg', 'tablet', 'oral'],
        ['Ibuprofen', '100 mg/5 mL', 'suspension', 'oral'], ['Mefenamic acid', '500 mg', 'capsule', 'oral'],
        ['Naproxen sodium', '550 mg', 'tablet', 'oral'], ['Celecoxib', '200 mg', 'capsule', 'oral'],
        ['Diclofenac sodium', '50 mg', 'tablet', 'oral'], ['Etoricoxib', '90 mg', 'tablet', 'oral'],
        ['Tramadol', '50 mg', 'capsule', 'oral'], ['Gabapentin', '300 mg', 'capsule', 'oral'],
        ['Pregabalin', '75 mg', 'capsule', 'oral'],
        // heart and blood pressure
        ['Amlodipine', '5 mg', 'tablet', 'oral'], ['Amlodipine', '10 mg', 'tablet', 'oral'],
        ['Losartan', '50 mg', 'tablet', 'oral'], ['Losartan', '100 mg', 'tablet', 'oral'],
        ['Losartan + hydrochlorothiazide', '50 mg/12.5 mg', 'tablet', 'oral'], ['Telmisartan', '40 mg', 'tablet', 'oral'],
        ['Metoprolol tartrate', '50 mg', 'tablet', 'oral'], ['Carvedilol', '6.25 mg', 'tablet', 'oral'],
        ['Atenolol', '50 mg', 'tablet', 'oral'], ['Hydrochlorothiazide', '25 mg', 'tablet', 'oral'],
        ['Furosemide', '40 mg', 'tablet', 'oral'], ['Spironolactone', '25 mg', 'tablet', 'oral'],
        ['Enalapril', '10 mg', 'tablet', 'oral'], ['Captopril', '25 mg', 'tablet', 'oral'],
        ['Atorvastatin', '20 mg', 'tablet', 'oral'], ['Atorvastatin', '40 mg', 'tablet', 'oral'],
        ['Rosuvastatin', '10 mg', 'tablet', 'oral'], ['Simvastatin', '20 mg', 'tablet', 'oral'],
        ['Clopidogrel', '75 mg', 'tablet', 'oral'], ['Aspirin', '80 mg', 'tablet', 'oral'],
        ['Isosorbide mononitrate', '30 mg', 'tablet', 'oral'], ['Trimetazidine', '35 mg', 'modified-release tablet', 'oral'],
        // diabetes and thyroid
        ['Metformin', '500 mg', 'tablet', 'oral'], ['Metformin', '850 mg', 'tablet', 'oral'],
        ['Gliclazide', '30 mg', 'modified-release tablet', 'oral'], ['Glimepiride', '2 mg', 'tablet', 'oral'],
        ['Sitagliptin', '100 mg', 'tablet', 'oral'], ['Levothyroxine', '50 mcg', 'tablet', 'oral'],
        // breathing and allergy
        ['Salbutamol', '2 mg', 'tablet', 'oral'], ['Salbutamol', '100 mcg/dose', 'inhaler', 'inhalation'],
        ['Salbutamol', '2.5 mg/2.5 mL', 'nebule', 'inhalation'],
        ['Budesonide + formoterol', '160 mcg/4.5 mcg', 'inhaler', 'inhalation'],
        ['Fluticasone propionate', '50 mcg/dose', 'nasal spray', 'nasal'], ['Montelukast', '10 mg', 'tablet', 'oral'],
        ['Cetirizine', '10 mg', 'tablet', 'oral'], ['Levocetirizine', '5 mg', 'tablet', 'oral'],
        ['Loratadine', '10 mg', 'tablet', 'oral'], ['Chlorphenamine', '4 mg', 'tablet', 'oral'],
        ['Diphenhydramine', '50 mg', 'capsule', 'oral'], ['Ambroxol', '30 mg', 'tablet', 'oral'],
        ['Carbocisteine', '500 mg', 'capsule', 'oral'], ['Prednisone', '10 mg', 'tablet', 'oral'],
        ['Prednisone', '20 mg', 'tablet', 'oral'],
        // stomach and bowel
        ['Omeprazole', '20 mg', 'capsule', 'oral'], ['Omeprazole', '40 mg', 'capsule', 'oral'],
        ['Pantoprazole', '40 mg', 'tablet', 'oral'], ['Esomeprazole', '40 mg', 'tablet', 'oral'],
        ['Famotidine', '20 mg', 'tablet', 'oral'], ['Domperidone', '10 mg', 'tablet', 'oral'],
        ['Metoclopramide', '10 mg', 'tablet', 'oral'], ['Hyoscine butylbromide', '10 mg', 'tablet', 'oral'],
        ['Loperamide', '2 mg', 'capsule', 'oral'], ['Oral rehydration salts', '', 'sachet', 'oral'],
        ['Aluminium hydroxide + magnesium hydroxide', '', 'suspension', 'oral'],
        ['Lactulose', '3.3 g/5 mL', 'syrup', 'oral'], ['Bisacodyl', '5 mg', 'tablet', 'oral'],
        // vitamins and minerals
        ['Ferrous sulfate', '325 mg', 'tablet', 'oral'], ['Folic acid', '5 mg', 'tablet', 'oral'],
        ['Calcium carbonate', '500 mg', 'tablet', 'oral'], ['Ascorbic acid', '500 mg', 'tablet', 'oral'],
        ['Vitamin B complex', '', 'tablet', 'oral'], ['Zinc sulfate', '20 mg/5 mL', 'syrup', 'oral'],
        // other
        ['Allopurinol', '100 mg', 'tablet', 'oral'], ['Colchicine', '500 mcg', 'tablet', 'oral'],
        ['Betahistine', '16 mg', 'tablet', 'oral'], ['Meclizine', '25 mg', 'tablet', 'oral'],
        ['Tranexamic acid', '500 mg', 'capsule', 'oral'], ['Sertraline', '50 mg', 'tablet', 'oral'],
        ['Escitalopram', '10 mg', 'tablet', 'oral'], ['Fluoxetine', '20 mg', 'capsule', 'oral'],
        ['Fluconazole', '150 mg', 'capsule', 'oral'], ['Albendazole', '400 mg', 'tablet', 'oral'],
        ['Mebendazole', '500 mg', 'tablet', 'oral'], ['Mupirocin', '2%', 'ointment', 'topical'],
        ['Hydrocortisone', '1%', 'cream', 'topical'], ['Clotrimazole', '1%', 'cream', 'topical'],
        ['Ketoconazole', '2%', 'cream', 'topical'], ['Tobramycin', '0.3%', 'eye drops', 'ophthalmic'],
        ['Ofloxacin', '0.3%', 'ear drops', 'otic'],
        // dangerous drugs: prescribing them needs the doctor's S2 number
        ['Diazepam', '5 mg', 'tablet', 'oral', true], ['Alprazolam', '250 mcg', 'tablet', 'oral', true],
        ['Clonazepam', '2 mg', 'tablet', 'oral', true], ['Phenobarbital', '30 mg', 'tablet', 'oral', true],
        ['Morphine sulfate', '10 mg', 'tablet', 'oral', true],
    ];

    public function run(): void
    {
        if (Medication::whereNull('clinic_id')->exists()) {
            return;
        }
        foreach (self::ITEMS as $i) {
            Medication::create(['generic_name' => $i[0], 'strength' => $i[1] ?: null, 'dosage_form' => $i[2],
                                'route' => $i[3], 'is_dangerous_drug' => $i[4] ?? false]);
        }
        $this->command?->info('Loaded '.count(self::ITEMS).' shared medicines.');
    }
}
