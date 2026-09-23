<?php

namespace App\Services\Rhb\Import;

use App\Enums\DepartmentBaseCategory;

/**
 * Classifies free-text department tokens (e.g. "循環器内科", "消内", "耳い")
 * into one of ~26 coarse base categories via substring/marker matching.
 *
 * Japan's 医療法施行規則 officially permits open-ended "qualifier + base
 * category" combination naming (e.g. "糖尿病・脂質代謝内科" = a qualifier +
 * 内科), so the real vocabulary is effectively unbounded free text -- exact
 * department-name fidelity is not attempted. This marker table recovered
 * 96.3% of observed department-token occurrences (84.9% of distinct
 * strings) across 92,041 real records sampled from all 8 bureaus.
 *
 * Marker order matters: it is deliberately organ/system-specific compounds
 * (循環器, 消化器, ...) before their generic base category (内科, 外科),
 * so e.g. "循環器内科" resolves to Cardiology rather than falling through
 * to the generic InternalMedicine match on "内科".
 */
final class DepartmentCategoryClassifier
{
    /**
     * @var array<string, DepartmentBaseCategory>
     */
    private const array MARKERS = [
        // Full official base-category names (most specific first).
        '耳鼻いんこう科' => DepartmentBaseCategory::Otolaryngology,
        'リハビリテーション科' => DepartmentBaseCategory::Rehabilitation,
        '臨床検査科' => DepartmentBaseCategory::ClinicalLaboratory,
        '病理診断科' => DepartmentBaseCategory::Pathology,
        '総合診療科' => DepartmentBaseCategory::GeneralPractice,
        'アレルギー科' => DepartmentBaseCategory::Allergy,
        'リウマチ科' => DepartmentBaseCategory::Rheumatology,
        '放射線科' => DepartmentBaseCategory::Radiology,
        '形成外科' => DepartmentBaseCategory::PlasticSurgery,
        '産婦人科' => DepartmentBaseCategory::Obstetrics,
        '泌尿器科' => DepartmentBaseCategory::Urology,
        '救急科' => DepartmentBaseCategory::EmergencyMedicine,
        '麻酔科' => DepartmentBaseCategory::Anesthesiology,
        '皮膚科' => DepartmentBaseCategory::Dermatology,
        '精神科' => DepartmentBaseCategory::Psychiatry,
        '神経科' => DepartmentBaseCategory::Neurology,
        '小児科' => DepartmentBaseCategory::Pediatrics,
        '肛門科' => DepartmentBaseCategory::Proctology,
        '胃腸科' => DepartmentBaseCategory::Gastroenterology,

        // Organ/system qualifiers that indicate a specific specialty
        // distinct from a bare 内科/外科 match.
        '循環器' => DepartmentBaseCategory::Cardiology,
        '消化器' => DepartmentBaseCategory::Gastroenterology,
        '呼吸器' => DepartmentBaseCategory::Respirology,
        '心臓' => DepartmentBaseCategory::Cardiology,
        '腎臓' => DepartmentBaseCategory::InternalMedicine,
        '肝臓' => DepartmentBaseCategory::Gastroenterology,
        '糖尿病' => DepartmentBaseCategory::InternalMedicine,

        // Short base-category names.
        '眼科' => DepartmentBaseCategory::Ophthalmology,
        '歯科' => DepartmentBaseCategory::Dentistry,
        '婦人科' => DepartmentBaseCategory::Gynecology,
        '産科' => DepartmentBaseCategory::Obstetrics,

        // 2-character abbreviations.
        '心内' => DepartmentBaseCategory::Cardiology,
        '心外' => DepartmentBaseCategory::Cardiology,
        '消内' => DepartmentBaseCategory::Gastroenterology,
        '循内' => DepartmentBaseCategory::Cardiology,
        '呼内' => DepartmentBaseCategory::Respirology,
        '脳外' => DepartmentBaseCategory::Neurology,
        '脳内' => DepartmentBaseCategory::Neurology,
        '神内' => DepartmentBaseCategory::Neurology,
        '歯外' => DepartmentBaseCategory::Dentistry,
        '形外' => DepartmentBaseCategory::PlasticSurgery,
        '整外' => DepartmentBaseCategory::Surgery,
        'リハ' => DepartmentBaseCategory::Rehabilitation,
        'リウ' => DepartmentBaseCategory::Rheumatology,
        'アレ' => DepartmentBaseCategory::Allergy,
        '耳い' => DepartmentBaseCategory::Otolaryngology,
        '産婦' => DepartmentBaseCategory::Obstetrics,
        'こう' => DepartmentBaseCategory::Proctology,
        '胃腸' => DepartmentBaseCategory::Gastroenterology,
        '救命' => DepartmentBaseCategory::EmergencyMedicine,
        '臨床' => DepartmentBaseCategory::ClinicalLaboratory,
        '病理' => DepartmentBaseCategory::Pathology,

        // 1-character fallbacks, checked last.
        '胃' => DepartmentBaseCategory::Gastroenterology,
        '腸' => DepartmentBaseCategory::Gastroenterology,
        '肝' => DepartmentBaseCategory::Gastroenterology,
        '腎' => DepartmentBaseCategory::InternalMedicine,
        '循' => DepartmentBaseCategory::Cardiology,
        '消' => DepartmentBaseCategory::Gastroenterology,
        '呼' => DepartmentBaseCategory::Respirology,
        '耳' => DepartmentBaseCategory::Otolaryngology,
        '眼' => DepartmentBaseCategory::Ophthalmology,
        '産' => DepartmentBaseCategory::Obstetrics,
        '婦' => DepartmentBaseCategory::Gynecology,
        '精' => DepartmentBaseCategory::Psychiatry,
        '神' => DepartmentBaseCategory::Neurology,
        'ひ' => DepartmentBaseCategory::Urology,
        '放' => DepartmentBaseCategory::Radiology,
        '麻' => DepartmentBaseCategory::Anesthesiology,
        '皮' => DepartmentBaseCategory::Dermatology,
        '小' => DepartmentBaseCategory::Pediatrics,
        '外' => DepartmentBaseCategory::Surgery,
        '内' => DepartmentBaseCategory::InternalMedicine,
        '歯' => DepartmentBaseCategory::Dentistry,
    ];

    /**
     * @param  list<string>  $tokens
     * @return list<DepartmentBaseCategory>
     */
    public function classify(array $tokens): array
    {
        $categories = [];

        foreach ($tokens as $token) {
            foreach (self::MARKERS as $marker => $category) {
                if (str_contains($token, $marker)) {
                    $categories[$category->value] = $category;

                    break;
                }
            }
        }

        return array_values($categories);
    }
}
