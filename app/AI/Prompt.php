<?php

declare(strict_types=1);

namespace App\AI;

/**
 * แปลง userPrompt ที่ระบบส่งให้ผู้ให้บริการ (เป็น JSON spec) ให้เป็นคำสั่งภาษาไทยสำหรับโมเดลจริง
 *
 * SimulatedProvider อ่าน spec เป็น JSON ตรง ๆ ส่วนโมเดลจริงต้องได้คำสั่งที่บอกรูปแบบผลลัพธ์
 * ให้ชัด เพราะ QuizGenerator / LessonPlanGenerator แปลงผลลัพธ์เป็น NDJSON ทีละบรรทัด
 */
final class Prompt
{
    /** ป้ายชื่อชนิดข้อสอบที่ใช้สื่อสารกับโมเดล */
    private const TYPE_LABELS = [
        'choice' => 'ปรนัย 4 ตัวเลือก',
        'short_answer' => 'อัตนัยตอบสั้น',
        'matching' => 'จับคู่',
    ];

    public static function userText(string $userPrompt): string
    {
        $spec = json_decode($userPrompt, true);
        if (!is_array($spec)) {
            return $userPrompt;
        }

        return match ($spec['task'] ?? '') {
            'chat' => (string) ($spec['message'] ?? ''),
            'quiz' => self::quiz($spec),
            'lesson_plan' => self::lessonPlan($spec),
            default => $userPrompt,
        };
    }

    /** @param array<string,mixed> $spec */
    private static function quiz(array $spec): string
    {
        $count = max(1, min(30, (int) ($spec['count'] ?? 5)));
        $types = array_map(
            static fn (string $t): string => self::TYPE_LABELS[$t] ?? $t,
            (array) ($spec['types'] ?? ['choice'])
        );
        $lessons = implode("\n- ", (array) ($spec['lesson_titles'] ?? []));
        $typeList = implode(' และ ', $types) ?: 'ปรนัย 4 ตัวเลือก';

        return <<<TXT
            ออกข้อสอบภาษาไทยจำนวน {$count} ข้อ สำหรับรายวิชา {$spec['course_code']} {$spec['course_name']}

            บทเรียนที่ต้องออกข้อสอบ:
            - {$lessons}

            ชนิดข้อสอบที่ต้องการ: {$typeList}
            ระดับความยาก: {$spec['level']}

            ข้อกำหนดของคำตอบ — สำคัญมาก ห้ามผิดรูปแบบ:
            1. ตอบเป็น NDJSON เท่านั้น หนึ่งข้อสอบต่อหนึ่งบรรทัด ห้ามครอบด้วย ``` ห้ามมีคำอธิบายนอก JSON
            2. แต่ละบรรทัดใช้รูปแบบนี้ (ข้อปรนัยต้องมี 4 ตัวเลือก และถูกต้องเพียงข้อเดียว):
            {"no":1,"type":"choice","question":"โจทย์","explanation":"เหตุผลของคำตอบที่ถูก","score":1,"choices":[{"label":"ก","content":"ตัวเลือก","is_correct":false},{"label":"ข","content":"ตัวเลือก","is_correct":true},{"label":"ค","content":"ตัวเลือก","is_correct":false},{"label":"ง","content":"ตัวเลือก","is_correct":false}]}
            3. ข้ออัตนัยใช้ "type":"short_answer" ให้ "choices" เป็น [] และ "score" เป็น 2
            4. เขียนโจทย์ให้ตรงกับเนื้อหาบทเรียนข้างต้นและบริบทงานอาชีพจริง ไม่ถามกว้างเกินไป
            5. เมื่อครบ {$count} ข้อ ให้ปิดท้ายด้วยบรรทัด {"done":true,"total":{$count}}
            TXT;
    }

    /** @param array<string,mixed> $spec */
    private static function lessonPlan(array $spec): string
    {
        $unit = (string) ($spec['unit'] ?? '');
        $hours = (string) ($spec['hours'] ?? '6');
        $competency = trim((string) ($spec['competency'] ?? '')) ?: '(ครูไม่ได้ระบุ ให้กำหนดให้เหมาะสมกับหน่วยนี้)';

        return <<<TXT
            ร่างแผนการจัดการเรียนรู้มุ่งเน้นสมรรถนะตามแบบฟอร์ม สอศ.

            หน่วยการเรียนรู้: {$unit}
            สมรรถนะที่ครูระบุ: {$competency}
            จำนวนชั่วโมง: {$hours} ชั่วโมง
            สัปดาห์ที่: {$spec['week']}
            รูปแบบการจัดการเรียนรู้: {$spec['style']}

            ข้อกำหนดของคำตอบ — สำคัญมาก ห้ามผิดรูปแบบ:
            1. ตอบเป็น NDJSON เท่านั้น หนึ่งหัวข้อต่อหนึ่งบรรทัด ห้ามครอบด้วย ``` ห้ามมีคำอธิบายนอก JSON
            2. บรรทัดแรกคือ {"meta":{"unit":"{$unit}","hours":"{$hours}"}}
            3. จากนั้นหนึ่งบรรทัดต่อหนึ่งหัวข้อ ในรูปแบบ {"head":"ชื่อหัวข้อ","body":"เนื้อหา"} ตามลำดับนี้
               1. สมรรถนะประจำหน่วย
               2. จุดประสงค์เชิงพฤติกรรม (เขียนเป็นข้อ 2.1–2.4 คั่นด้วย \\n)
               3. สาระการเรียนรู้ (เขียนเป็นข้อ 3.1–3.4 คั่นด้วย \\n)
               4. กิจกรรมการเรียนรู้ ({$hours} ชั่วโมง) — แยกขั้นนำ ขั้นสอน ขั้นปฏิบัติ ขั้นสรุป พร้อมเวลาแต่ละขั้นรวมกันได้ตามจำนวนชั่วโมง
               5. สื่อและแหล่งการเรียนรู้
               6. การวัดและประเมินผล — ระบุคะแนนแต่ละส่วนและเกณฑ์ผ่าน
               7. บันทึกหลังการสอน — ให้เว้นไว้ว่า (ครูกรอกหลังจัดการเรียนการสอนจริง)
            4. ขึ้นบรรทัดใหม่ภายใน body ให้ใช้ \\n ห้ามขึ้นบรรทัดจริง เพราะหนึ่งบรรทัดต้องเป็น JSON หนึ่งชุด
            5. ปิดท้ายด้วยบรรทัด {"done":true}
            TXT;
    }
}
