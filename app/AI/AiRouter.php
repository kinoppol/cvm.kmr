<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AiRepository;
use App\Domain\SettingsRepository;

/**
 * เลือกเส้นทาง AI ตามลำดับ: คีย์ของครู → เครื่องของวิทยาลัย → แจ้งเตือนเมื่อทั้งสองใช้ไม่ได้
 *
 * ระหว่างที่ยังไม่มีเครื่อง GPU จริง ทั้งสองเส้นทางใช้ SimulatedProvider
 * โดยยังคงป้ายกำกับและตรรกะโควตา/คิวตามจริง เพื่อให้สลับมาใช้ของจริงได้ทันที
 */
final class AiRouter
{
    public function __construct(
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
    ) {
    }

    /** @param 'fast'|'quality' $quality */
    public function route(int $userId, string $quality = 'fast'): AiRoute
    {
        $modeLabel = $quality === 'quality' ? 'โหมดคุณภาพสูง' : 'โหมดเร็ว';

        // 1) คีย์ของครู
        if ($this->settings->bool('allow_teacher_byok', true)) {
            $key = $this->ai->activeKeyForUser($userId);
            if ($key !== null) {
                $provider = $this->byokProvider($key, $quality);
                if ($provider->isAvailable()) {
                    $this->ai->touchKeyUsed((int) $key['id']);

                    return new AiRoute(
                        provider: $provider,
                        source: 'byok',
                        chip: 'AI ของฉัน · ' . $modeLabel,
                        wait: 'ประมาณ 8 วินาที · ไม่ใช้โควตาของวิทยาลัย',
                        countsQuota: false,
                        model: (string) ($key['model'] ?? $key['provider']),
                    );
                }
                // คีย์ใช้ไม่ได้ → ตกไปใช้เครื่องของวิทยาลัยต่อ
            }
        }

        // 2) เครื่องของวิทยาลัย
        $endpoint = $this->ai->defaultEndpoint();
        if ($endpoint !== null && $endpoint['status'] !== 'offline') {
            $quotaLimit = $this->settings->int('ai_monthly_quota', 60);
            $quota = $this->ai->quota($userId, $this->period(), $quotaLimit);

            if ($quota['left'] <= 0) {
                throw new AiUnavailableException(
                    'quota_exhausted',
                    sprintf('ใช้โควตาผู้ช่วย AI ครบ %d ครั้งของเดือนนี้แล้ว', $quota['limit'])
                );
            }

            $queue = max((int) $endpoint['queue_length'], $this->ai->queueLength());
            $waitSeconds = 15 + $queue * 12;

            return new AiRoute(
                provider: $this->collegeProvider($endpoint),
                source: 'college',
                chip: $queue > 0 ? sprintf('AI วิทยาลัย · คิว %d งาน', $queue) : 'AI วิทยาลัย',
                wait: $queue > 5
                    ? sprintf('มีครูใช้งานพร้อมกันหลายคน · คิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds))
                    : sprintf('มีคิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds)),
                countsQuota: true,
                model: (string) $endpoint['model'],
            );
        }

        throw new AiUnavailableException(
            'college_offline',
            'ตอนนี้เครื่อง AI ของวิทยาลัยปิดอยู่ และยังไม่ได้เชื่อม AI ของครู'
        );
    }

    private function collegeProvider(array $endpoint): AiProvider
    {
        // TODO: เปลี่ยนเป็น OllamaProvider เมื่อมีเครื่องจริง
        // return new OllamaProvider($endpoint['base_url'], $endpoint['model']);
        return new SimulatedProvider('AI ของวิทยาลัย', $endpoint['status'] !== 'offline');
    }

    private function byokProvider(array $key, string $quality): AiProvider
    {
        // TODO: เปลี่ยนเป็น OpenAiCompatibleProvider($baseUrl, $this->cipher->decrypt($key['key_encrypted']), $model)
        $name = match ($key['provider']) {
            'google' => 'Gemini',
            'openrouter' => 'OpenRouter',
            default => 'บริการของฉัน',
        };

        return new SimulatedProvider('AI ของฉัน · ' . $name, true, 300);
    }

    private function period(): string
    {
        return sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n'));
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' วินาที';
        }

        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $s > 0 ? sprintf('%d นาที %d วินาที', $m, $s) : sprintf('%d นาที', $m);
    }
}
