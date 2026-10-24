<?php
declare(strict_types=1);

function descriptive_normalize_answer(string $answer, bool $ignorePunctuation): string
{
    $answer = function_exists('mb_strtolower') ? mb_strtolower(trim($answer), 'UTF-8') : strtolower(trim($answer));
    if ($ignorePunctuation) {
        $answer = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $answer) ?? $answer;
    }
    return preg_replace('/\s+/u', ' ', trim($answer)) ?? trim($answer);
}

function mark_descriptive_answer(
    string $answer,
    array $acceptedAnswers,
    array $keywordRules,
    float $maxMarks,
    ?float $numericTolerance,
    bool $ignorePunctuation,
    int $spellingTolerance
): array {
    $normalized = descriptive_normalize_answer($answer, $ignorePunctuation);
    if ($normalized === '') {
        return ['status' => 'auto_marked', 'marks' => 0.0, 'matched' => [], 'explanation' => 'No answer was provided; zero marks awarded.'];
    }

    $accepted = [];
    foreach ($acceptedAnswers as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $accepted[] = descriptive_normalize_answer($candidate, $ignorePunctuation);
        }
    }
    $studentNumber = filter_var(trim($answer), FILTER_VALIDATE_FLOAT);
    foreach ($accepted as $index => $candidate) {
        $acceptedRaw = $acceptedAnswers[$index] ?? '';
        $expectedNumber = is_string($acceptedRaw) ? filter_var(trim($acceptedRaw), FILTER_VALIDATE_FLOAT) : false;
        if ($studentNumber !== false && $expectedNumber !== false) {
            if ((float)$studentNumber === (float)$expectedNumber) {
                return ['status' => 'auto_marked', 'marks' => $maxMarks, 'matched' => [], 'explanation' => 'Answer matches a teacher-approved numeric answer.'];
            }
            continue;
        }
        if ($studentNumber !== false || $expectedNumber !== false) {
            continue;
        }
        if ($normalized === $candidate) {
            return ['status' => 'auto_marked', 'marks' => $maxMarks, 'matched' => [], 'explanation' => 'Answer matches a teacher-approved accepted answer.'];
        }
    }

    if ($numericTolerance !== null && $numericTolerance >= 0 && count($accepted) > 0) {
        foreach ($acceptedAnswers as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $expectedNumber = filter_var(trim($candidate), FILTER_VALIDATE_FLOAT);
            if ($studentNumber !== false && $expectedNumber !== false && abs((float)$studentNumber - (float)$expectedNumber) <= $numericTolerance) {
                return ['status' => 'auto_marked', 'marks' => $maxMarks, 'matched' => [], 'explanation' => 'Numeric answer is within the configured tolerance.'];
            }
        }
    }

    if ($spellingTolerance > 0 && strlen($normalized) <= 256 && preg_match('/^[\x00-\x7F]*$/', $normalized)) {
        foreach ($accepted as $candidate) {
            if (strlen($candidate) <= 256 && preg_match('/^[\x00-\x7F]*$/', $candidate) && levenshtein($normalized, $candidate) <= $spellingTolerance) {
                return ['status' => 'auto_marked', 'marks' => $maxMarks, 'matched' => [], 'explanation' => 'Answer is within the teacher-configured spelling tolerance.'];
            }
        }
    }

    if (!$keywordRules) {
        return ['status' => 'pending_review', 'marks' => null, 'matched' => [], 'explanation' => 'Answer did not match an accepted answer and no deterministic partial-credit rubric applies.'];
    }

    $matched = [];
    $score = 0.0;
    $haystack = ' '.$normalized.' ';
    foreach ($keywordRules as $rule) {
        if (!is_array($rule) || !isset($rule['phrase'], $rule['marks']) || !is_string($rule['phrase'])) {
            continue;
        }
        $phrase = descriptive_normalize_answer($rule['phrase'], $ignorePunctuation);
        if ($phrase !== '' && str_contains($haystack, ' '.$phrase.' ')) {
            $matched[] = $rule['phrase'];
            $score += (float)$rule['marks'];
        }
    }
    if ($matched) {
        $score = min($maxMarks, $score);
        return ['status' => 'auto_marked', 'marks' => $score, 'matched' => $matched, 'explanation' => 'Marks awarded for matched teacher-defined keywords or phrases.'];
    }
    return ['status' => 'auto_marked', 'marks' => 0.0, 'matched' => [], 'explanation' => 'No teacher-defined keywords or phrases matched; zero marks awarded.'];
}
