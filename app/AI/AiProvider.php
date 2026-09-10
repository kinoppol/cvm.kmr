<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ผู้ให้บริการ AI — มีสองแบบ: เครื่องของวิทยาลัย (Ollama) และคีย์ของครู (OpenAI-compatible)
 * ระหว่างยังไม่มีเครื่องจริง ใช้ SimulatedProvider แทน
 */
interface AiProvider
{
    /**
     * สตรีมคำตอบเป็นก้อนข้อความทีละก้อน (generator ของ string)
     *
     * @param 'fast'|'quality' $quality โหมดคุณภาพ
     * @return iterable<string>
     */
    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable;

    /** ชื่อที่ครูเห็น เช่น "AI ของวิทยาลัย" หรือ "AI ของฉัน · Gemini Flash" */
    public function label(): string;

    /** ตรวจว่าพร้อมใช้งานหรือไม่ (เช็คคิว/สถานะ) */
    public function isAvailable(): bool;
}
