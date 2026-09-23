<?php

namespace App\Enums;

/**
 * Coarse "which specialty family" classification for a facility's
 * standing 診療科目 (departments), derived from Japan's officially
 * permitted 標榜診療科名 base categories (医療法施行規則). The source data
 * allows open-ended qualifier+base-category combination naming (e.g.
 * "糖尿病・脂質代謝内科" = a qualifier + 内科), so exact department-name
 * fidelity is not attempted here -- only which of these ~26 base
 * categories a department name matches, via marker/substring matching in
 * DepartmentCategoryClassifier. This provisional case list is derived from
 * real-data research (recovers 96.3% of observed department-token
 * occurrences); it may be adjusted once Phase A-2's Hokkaido spike
 * confirms the final observed vocabulary.
 */
enum DepartmentBaseCategory: int
{
    case InternalMedicine = 1;
    case Surgery = 2;
    case Pediatrics = 3;
    case Dermatology = 4;
    case Ophthalmology = 5;
    case Otolaryngology = 6;
    case Obstetrics = 7;
    case Gynecology = 8;
    case Rehabilitation = 9;
    case Radiology = 10;
    case Anesthesiology = 11;
    case Urology = 12;
    case Psychiatry = 13;
    case Neurology = 14;
    case Dentistry = 15;
    case Rheumatology = 16;
    case Allergy = 17;
    case Proctology = 18;
    case PlasticSurgery = 19;
    case Pathology = 20;
    case ClinicalLaboratory = 21;
    case EmergencyMedicine = 22;
    case Cardiology = 23;
    case Gastroenterology = 24;
    case Respirology = 25;
    case GeneralPractice = 26;
}
