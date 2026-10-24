<?php
// CareerPath AI - Skills verification helper
// --------------------------------------------------------------------

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

function skill_match_message(array $skillMatch): array
{
    $total = (int) ($skillMatch['required_count'] ?? 0);
    $have = count($skillMatch['matched'] ?? []);
    if ($have > 0) {
        return [
            'has_match' => true,
            'text' => "✨ Wow, your current skills match what this career needs! You already have $have of its $total key skills, so you've got a head start. This career is worth a closer look.",
            'label' => 'Skills needed for this career:',
        ];
    }
    return [
        'has_match' => false,
        'text' => '',
        'label' => 'Skills needed for this career:',
    ];
}
