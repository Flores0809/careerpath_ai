<?php
// CareerPath AI - Skills verification helper
// --------------------------------------------------------------------
// Specific Objective 2 / Research Gap #4: compare a student's self-reported
// skills against a career's required skills (SKILL_REQUIREMENTS entity in
// the ERD) so recommendations show not just RIASEC fit but a concrete,
// actionable skills gap. Skills are free text on both sides (student input
// on php/assessment.php, counselor-entered requirements on
// php/careers_manage.php), so matching here is a simple, case-insensitive,
// trimmed comparison — consistent with the project's rule-based approach
// (no custom-trained ML, per the paper's Technology Stack section).

/**
 * Split a comma/newline-separated free-text skills string into a clean list.
 */
function parse_skill_list(?string $raw): array
{
    if (!$raw) {
        return [];
    }
    $parts = preg_split('/[,\n]+/', $raw);
    $skills = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $skills[] = $p;
        }
    }
    return $skills;
}

/**
 * Compare a student's free-text skills against a career's required skills.
 * Returns null match_percent when the career has no skills defined yet
 * (nothing to verify against), rather than a misleading 0% or 100%.
 */
function compute_skill_match(PDO $pdo, int $careerId, ?string $studentSkillsRaw): array
{
    $studentSkills = parse_skill_list($studentSkillsRaw);
    $studentSkillsLower = array_map('mb_strtolower', $studentSkills);

    $stmt = $pdo->prepare(
        "SELECT skill_req_id, skill_name, proficiency_level, is_required
         FROM skill_requirements
         WHERE career_id = :id
         ORDER BY is_required DESC, skill_name"
    );
    $stmt->execute(['id' => $careerId]);
    $required = $stmt->fetchAll();

    $matched = [];
    $missing = [];
    foreach ($required as $req) {
        $reqLower = mb_strtolower($req['skill_name']);
        $hasIt = false;
        foreach ($studentSkillsLower as $s) {
            if ($s === $reqLower || str_contains($s, $reqLower) || str_contains($reqLower, $s)) {
                $hasIt = true;
                break;
            }
        }
        if ($hasIt) {
            $matched[] = $req;
        } else {
            $missing[] = $req;
        }
    }

    $total = count($required);

    return [
        'required_count' => $total,
        'matched' => $matched,
        'missing' => $missing,
        'match_percent' => $total > 0 ? round((count($matched) / $total) * 100) : null,
    ];
}
