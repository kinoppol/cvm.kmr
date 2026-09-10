<?php

declare(strict_types=1);

namespace App\AI;

/**
 * สร้างร่างข้อสอบจากบทเรียนที่ครูเลือก โดยส่ง prompt ให้ผู้ให้บริการ AI
 * แล้วแปลงผลลัพธ์ NDJSON เป็นโครงสร้างข้อสอบทีละข้อ
 */
final class QuizGenerator
{
    private const TYPE_MAP = [
        'ปรนัย' => 'choice',
        'อัตนัย' => 'short_answer',
        'จับคู่' => 'matching',
    ];

    /**
     * @param array{course_code:string,course_name:string,lesson_titles:list<string>,count:int,types:list<string>,level:string} $params
     * @return iterable<array{event:string,data?:array,total?:int,message?:string}>
     */
    public function stream(AiRoute $route, array $params, string $quality = 'fast'): iterable
    {
        $types = array_values(array_map(
            static fn (string $t): string => self::TYPE_MAP[$t] ?? $t,
            $params['types'] ?: ['ปรนัย']
        ));

        $spec = json_encode([
            'task' => 'quiz',
            'course_code' => $params['course_code'],
            'course_name' => $params['course_name'],
            'lesson_titles' => $params['lesson_titles'],
            'count' => $params['count'],
            'types' => $types,
            'level' => $params['level'],
        ], JSON_UNESCAPED_UNICODE);

        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษา ออกข้อสอบภาษาไทยที่ถูกต้องตามหลักวิชาชีพ '
            . 'ตอบกลับเป็น NDJSON หนึ่งข้อต่อหนึ่งบรรทัด';

        $buffer = '';
        foreach ($route->provider->stream($system, $spec, $quality) as $chunk) {
            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if ($line === '') {
                    continue;
                }

                $obj = json_decode($line, true);
                if (!is_array($obj)) {
                    continue;
                }

                if (isset($obj['error'])) {
                    yield ['event' => 'error', 'message' => (string) $obj['error']];

                    return;
                }
                if (!empty($obj['done'])) {
                    yield ['event' => 'done', 'total' => (int) ($obj['total'] ?? 0)];

                    return;
                }

                yield ['event' => 'question', 'data' => $this->normalise($obj)];
            }
        }

        yield ['event' => 'done', 'total' => 0];
    }

    /** @return array<string,mixed> */
    private function normalise(array $obj): array
    {
        $choices = [];
        foreach ($obj['choices'] ?? [] as $i => $c) {
            $choices[] = [
                'label' => (string) ($c['label'] ?? ['ก', 'ข', 'ค', 'ง', 'จ'][$i] ?? ''),
                'content' => (string) ($c['content'] ?? ''),
                'is_correct' => !empty($c['is_correct']),
            ];
        }

        return [
            'no' => (int) ($obj['no'] ?? 0),
            'type' => in_array($obj['type'] ?? 'choice', ['choice', 'short_answer', 'matching'], true) ? $obj['type'] : 'choice',
            'question' => trim((string) ($obj['question'] ?? '')),
            'explanation' => trim((string) ($obj['explanation'] ?? '')),
            'score' => (float) ($obj['score'] ?? 1.0),
            'choices' => $choices,
        ];
    }
}
