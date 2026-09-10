<?php

declare(strict_types=1);

namespace App\Install;

use App\Domain\SettingsRepository;
use App\Support\Db;

/**
 * ใส่ข้อมูลตัวอย่างที่สมจริงตามหลักสูตร ปวช./ปวส. ของวิทยาลัยเทคนิคร้อยเอ็ด
 *
 * ครอบคลุม: สาขาวิชา กลุ่มเรียน ครูนำร่อง นักเรียน รายวิชา บทเรียน
 * เครื่อง AI ของวิทยาลัย โควตา และเนื้อหาตัวอย่างที่รอตรวจ
 *
 * รันซ้ำได้ — จะล้างข้อมูลตัวอย่างเดิม (เฉพาะส่วนการเรียนการสอน) แล้วสร้างใหม่
 */
final class DemoSeeder
{
    public const DEMO_PASSWORD = 'rvclearn2569';

    /** ตารางที่จะถูกล้างก่อน seed ใหม่ เรียงตามลำดับ FK */
    private const RESET_TABLES = [
        'ai_generations', 'ai_usage_logs', 'ai_jobs', 'ai_quotas', 'user_api_keys', 'ai_endpoints',
        'quiz_answers', 'quiz_attempts', 'quiz_choices', 'quiz_questions', 'quizzes',
        'submissions', 'assignments',
        'lesson_attachments', 'lessons', 'enrollments', 'courses', 'classrooms', 'departments',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @return list<string> บันทึกการทำงานทีละบรรทัด */
    public function run(): array
    {
        $log = [];
        $year = $this->settings->int('academic_year', 2569);
        $semester = $this->settings->int('semester', 1);
        $termId = $this->currentTermId($year, $semester);

        $this->reset();
        $log[] = 'ล้างข้อมูลตัวอย่างเดิมแล้ว';

        $hash = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);

        // ---- สาขาวิชา ----
        $dept = [];
        foreach ([
            'MECH' => 'ช่างกลโรงงาน',
            'AUTO' => 'ช่างยนต์',
            'ELEC' => 'ช่างไฟฟ้ากำลัง',
            'ELTX' => 'ช่างอิเล็กทรอนิกส์',
            'COMP' => 'เทคโนโลยีธุรกิจดิจิทัล',
        ] as $code => $name) {
            $dept[$code] = $this->db->insert('departments', ['code' => $code, 'name' => $name]);
        }
        $log[] = 'เพิ่มสาขาวิชา 5 สาขา';

        // ---- ครูนำร่อง ----
        $teachers = [
            'thanaphon' => ['อ.ธนพล ศรีบุญเรือง', 'thanaphon@rvc.ac.th', $dept['MECH']],
            'sunisa'    => ['อ.สุนิสา จันทร์เพ็ง', 'sunisa@rvc.ac.th', $dept['ELEC']],
            'weerachai' => ['อ.วีระชัย นาคำ', 'weerachai@rvc.ac.th', $dept['AUTO']],
            'patcharee' => ['อ.พัชรี โคตรสมบัติ', 'patcharee@rvc.ac.th', $dept['COMP']],
            'anucha'    => ['อ.อนุชา ภูมิเพ็ง', 'anucha@rvc.ac.th', $dept['MECH']],
            'kamonchanok' => ['อ.กมลชนก ศรีสุธรรม', 'kamonchanok@rvc.ac.th', $dept['COMP']],
        ];
        $teacherId = [];
        foreach ($teachers as $username => [$fullName, $email, $_deptId]) {
            $teacherId[$username] = $this->upsertUser($username, $fullName, $email, 'teacher', $hash);
        }
        $log[] = 'เพิ่มครูนำร่อง 6 คน (รหัสผ่านตัวอย่าง: ' . self::DEMO_PASSWORD . ')';

        // ---- กลุ่มเรียน ----
        $classrooms = [
            'mech2' => ['ปวช.2 ช่างกลโรงงาน', $dept['MECH'], 'pvch', 2, $teacherId['thanaphon']],
            'mech3' => ['ปวช.3 ช่างกลโรงงาน', $dept['MECH'], 'pvch', 3, $teacherId['anucha']],
            'auto1' => ['ปวช.1 ช่างยนต์', $dept['AUTO'], 'pvch', 1, $teacherId['weerachai']],
            'elec_s1' => ['ปวส.1 ไฟฟ้ากำลัง', $dept['ELEC'], 'pvs', 1, $teacherId['sunisa']],
        ];
        $classId = [];
        foreach ($classrooms as $key => [$name, $deptId, $level, $yearLevel, $advisor]) {
            $classId[$key] = $this->db->insert('classrooms', [
                'department_id' => $deptId,
                'advisor_id' => $advisor,
                'level' => $level,
                'year_level' => $yearLevel,
                'name' => $name,
            ]);
        }
        $log[] = 'เพิ่มกลุ่มเรียน 4 กลุ่ม';

        // ---- นักเรียน ----
        $studentIds = $this->seedStudents($hash, 28);
        $log[] = 'เพิ่มนักเรียน ' . count($studentIds) . ' คน';

        // ---- รายวิชาของ อ.ธนพล ----
        $courses = [
            'mecha' => ['20127-2002', 'เมคคาทรอนิกส์เบื้องต้น', 3.0, 1, 4, $classId['mech2'],
                'ศึกษาและปฏิบัติเกี่ยวกับหลักการทำงานของระบบเมคคาทรอนิกส์ เซนเซอร์ ตัวขับเคลื่อน ระบบนิวแมติกส์ และการควบคุมด้วยตัวควบคุมเชิงตรรกะ'],
            'elec'  => ['20100-1005', 'งานไฟฟ้าและอิเล็กทรอนิกส์เบื้องต้น', 2.0, 1, 3, $classId['auto1'],
                'ศึกษาและปฏิบัติเกี่ยวกับกฎของโอห์ม วงจรไฟฟ้ากระแสตรง อุปกรณ์อิเล็กทรอนิกส์พื้นฐาน และความปลอดภัยในงานไฟฟ้า'],
            'micro' => ['30128-2003', 'ไมโครคอนโทรลเลอร์', 3.0, 2, 3, $classId['elec_s1'],
                'ศึกษาและปฏิบัติเกี่ยวกับสถาปัตยกรรมไมโครคอนโทรลเลอร์ การเขียนโปรแกรมควบคุม พอร์ตอินพุตเอาต์พุต และการเชื่อมต่ออุปกรณ์ภายนอก'],
            'pneu'  => ['20127-2005', 'นิวแมติกส์และไฮดรอลิกส์', 3.0, 1, 4, $classId['mech3'],
                'ศึกษาและปฏิบัติเกี่ยวกับหลักการของระบบลมอัดและระบบไฮดรอลิกส์ การอ่านวงจร การต่อวงจรควบคุมกระบอกสูบ และความปลอดภัย'],
        ];
        $courseId = [];
        foreach ($courses as $key => [$code, $name, $credits, $theory, $practice, $classroom, $desc]) {
            $courseId[$key] = $this->db->insert('courses', [
                'code' => $code,
                'name' => $name,
                'credits' => $credits,
                'theory_hours' => $theory,
                'practice_hours' => $practice,
                'description' => $desc,
                'teacher_id' => $teacherId['thanaphon'],
                'term_id' => $termId,
                'classroom_id' => $classroom,
                'status' => 'active',
            ]);
        }
        $log[] = 'เพิ่มรายวิชาของ อ.ธนพล 4 รายวิชา';

        // ---- ลงทะเบียนนักเรียน ----
        $this->enroll($courseId['mecha'], array_slice($studentIds, 0, 28));
        $this->enroll($courseId['elec'], array_slice($studentIds, 0, 20));
        $this->enroll($courseId['micro'], array_slice($studentIds, 0, 14));
        $this->enroll($courseId['pneu'], array_slice($studentIds, 6, 18));
        $log[] = 'ลงทะเบียนนักเรียนเข้ารายวิชาแล้ว';

        // ---- บทเรียนวิชาเมคคาทรอนิกส์ ----
        $this->seedMechatronicsLessons($courseId['mecha'], $teacherId['thanaphon']);
        $this->seedElectricalLessons($courseId['elec'], $teacherId['thanaphon']);
        $log[] = 'เพิ่มบทเรียนตัวอย่าง';

        // ---- เครื่อง AI ของวิทยาลัย ----
        $endpointId = $this->db->insert('ai_endpoints', [
            'name' => 'AI ของวิทยาลัย',
            'base_url' => 'http://10.10.0.12:11434',
            'model' => 'typhoon2-8b-instruct',
            'is_default' => 1,
            'status' => 'online',
            'queue_length' => 2,
            'notes' => 'เครื่อง GPU ในห้องเซิร์ฟเวอร์ · รัน Ollama',
            'last_checked_at' => date('Y-m-d H:i:s'),
        ]);
        $log[] = 'ตั้งค่าเครื่อง AI ของวิทยาลัย';

        // ---- โควตารายครู เดือนปัจจุบัน ----
        $period = sprintf('%04d-%02d', $year, (int) date('n'));
        $limit = $this->settings->int('ai_monthly_quota', 60);
        $used = [
            'thanaphon' => 18, 'sunisa' => 27, 'weerachai' => 31,
            'patharee' => 59, 'anucha' => 12, 'kamonchanok' => 60,
        ];
        foreach ($teacherId as $username => $id) {
            $this->db->insert('ai_quotas', [
                'user_id' => $id,
                'period' => $period,
                'monthly_limit' => $limit,
                'used_count' => $used[$username] ?? 0,
            ]);
        }
        $this->db->insert('ai_quotas', ['user_id' => null, 'period' => $period, 'monthly_limit' => $limit, 'used_count' => 0]);
        $log[] = 'ตั้งค่าโควตาผู้ช่วย AI ของครูแต่ละคน';

        // ---- เนื้อหาตัวอย่างที่รอตรวจ ----
        $this->seedPendingReviews($teacherId['thanaphon'], $courseId, $endpointId);
        $log[] = 'เพิ่มเนื้อหาตัวอย่างที่รอตรวจ 3 รายการ';

        $this->settings->set('demo_seeded', '1', 'boolean', 'general');
        $this->settings->set('demo_seeded_at', date('Y-m-d H:i:s'), 'string', 'general');
        $log[] = 'เสร็จสิ้น';

        return $log;
    }

    public function alreadySeeded(): bool
    {
        return $this->settings->bool('demo_seeded');
    }

    private function reset(): void
    {
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::RESET_TABLES as $table) {
                $this->db->pdo()->exec(sprintf('DELETE FROM `%s`', $this->db->table($table)));
            }
            // ลบเฉพาะบัญชีครู/นักเรียนตัวอย่าง (ไม่แตะ admin)
            $this->db->run("DELETE FROM {users} WHERE role IN ('teacher','student')");
        } finally {
            $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function currentTermId(int $year, int $semester): int
    {
        $id = $this->db->int('SELECT id FROM {academic_terms} WHERE academic_year = ? AND semester = ?', [$year, $semester]);
        if ($id > 0) {
            return $id;
        }

        return $this->db->insert('academic_terms', [
            'academic_year' => $year,
            'semester' => $semester,
            'name' => sprintf('ภาคเรียนที่ %d / %d', $semester, $year),
            'is_current' => 1,
        ]);
    }

    private function upsertUser(string $username, string $fullName, ?string $email, string $role, string $hash): int
    {
        $existing = $this->db->int('SELECT id FROM {users} WHERE username = ?', [$username]);
        if ($existing > 0) {
            $this->db->update('users', ['full_name' => $fullName, 'email' => $email, 'role' => $role, 'status' => 'active'], ['id' => $existing]);

            return $existing;
        }

        return $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $hash,
            'full_name' => $fullName,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** @return list<int> */
    private function seedStudents(string $hash, int $count): array
    {
        $first = ['ณัฐวุฒิ', 'ปิยะดา', 'อภิสิทธิ์', 'สมฤทัย', 'ธนกฤต', 'กนกวรรณ', 'จิรายุ', 'พรนภา',
            'ศักดิ์สิทธิ์', 'วรรณิษา', 'ธีรภัทร', 'อรอุมา', 'ณัฐพงษ์', 'สุพัตรา', 'กิตติศักดิ์', 'มธุรดา',
            'ภาณุพงศ์', 'ชลธิชา', 'รัชชานนท์', 'เบญจวรรณ', 'อนุชิต', 'ปนัดดา', 'วีรภัทร', 'สิริวิมล',
            'จักรพันธ์', 'นันทิชา', 'พีรพัฒน์', 'ขวัญจิรา'];
        $last = ['แสนสุข', 'วงศ์อามาตย์', 'ไชยโพธิ์', 'บุตรพรม', 'โพธิ์ศรี', 'ทองจันทร์', 'ศรีสมบัติ', 'คำมะฤทธิ์',
            'พันธ์ดี', 'สุขสมบูรณ์', 'อ่อนสิม', 'นาสมวงศ์', 'ดวงจันทร์', 'มาลาหอม', 'บุญมี', 'สอนสิทธิ์',
            'ประทุมมา', 'จำปาทอง', 'เหล่ามาลา', 'สีดาคำ', 'พรมลี', 'ชัยศรี', 'ก้อนคำ', 'หงษ์ทอง',
            'ศรีทา', 'วอทอง', 'เวียงคำ', 'ผองพันธ์'];

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $seq = $i + 1;
            $username = sprintf('66201%03d', $seq);
            $name = $first[$i % count($first)] . ' ' . $last[$i % count($last)];
            $ids[] = $this->db->insert('users', [
                'username' => $username,
                'email' => null,
                'password_hash' => $hash,
                'full_name' => $name,
                'role' => 'student',
                'status' => 'active',
            ]);
        }

        return $ids;
    }

    /** @param list<int> $studentIds */
    private function enroll(int $courseId, array $studentIds): void
    {
        foreach ($studentIds as $studentId) {
            $this->db->run(
                'INSERT IGNORE INTO {enrollments} (course_id, student_id, status) VALUES (?, ?, \'active\')',
                [$courseId, $studentId]
            );
        }
    }

    private function seedMechatronicsLessons(int $courseId, int $authorId): void
    {
        $lessons = [
            ['ความรู้เบื้องต้นเกี่ยวกับระบบเมคคาทรอนิกส์', 'ระบบเมคคาทรอนิกส์คือการรวมศาสตร์เครื่องกล ไฟฟ้า อิเล็กทรอนิกส์ และการควบคุมเข้าด้วยกัน', 'published'],
            ['ระบบไฟฟ้าและการควบคุมพื้นฐาน', 'แรงดัน กระแส ความต้านทาน รีเลย์ และการต่อวงจรควบคุมเบื้องต้น', 'published'],
            ['เซนเซอร์และอุปกรณ์ตรวจจับ', 'พร็อกซิมิตีเซนเซอร์แบบเหนี่ยวนำและแบบเก็บประจุ สวิตช์ลิมิต และการเลือกใช้งาน', 'published'],
            ['ระบบนิวแมติกส์เบื้องต้น', 'องค์ประกอบของระบบลมอัด ชุดกรองลม วาล์วควบคุมทิศทาง 3/2 และ 5/2 กระบอกสูบทางเดียวและสองทาง', 'published'],
            ['ตัวขับเคลื่อนและมอเตอร์ไฟฟ้า', 'มอเตอร์กระแสตรง มอเตอร์สเต็ปเปอร์ และเซอร์โวมอเตอร์ การเลือกใช้ตามลักษณะงาน', 'draft'],
            ['การควบคุมด้วยตัวควบคุมเชิงตรรกะ', 'หลักการของ PLC โครงสร้างโปรแกรม และการเขียนวงจรควบคุมแบบแลดเดอร์', 'draft'],
            ['ระบบควบคุมแบบวงเปิดและวงปิด', 'ความแตกต่างของการควบคุมสองแบบ การป้อนกลับ และตัวอย่างในงานอุตสาหกรรม', 'draft'],
            ['การประยุกต์ใช้ระบบเมคคาทรอนิกส์ในงานจริง', 'สายการผลิตอัตโนมัติ แขนกล และระบบคัดแยกชิ้นงาน', 'draft'],
        ];

        foreach ($lessons as $order => [$title, $summary, $status]) {
            $this->db->insert('lessons', [
                'course_id' => $courseId,
                'title' => $title,
                'summary' => $summary,
                'content' => null,
                'sort_order' => $order + 1,
                'source' => 'manual',
                'review_status' => $status,
                'created_by' => $authorId,
                'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }

    private function seedElectricalLessons(int $courseId, int $authorId): void
    {
        $lessons = [
            ['ความปลอดภัยในงานไฟฟ้า', 'อันตรายจากไฟฟ้า การป้องกัน อุปกรณ์ป้องกันส่วนบุคคล และการปฐมพยาบาลผู้ถูกไฟฟ้าดูด'],
            ['กฎของโอห์มและวงจรไฟฟ้ากระแสตรง', 'ความสัมพันธ์ของแรงดัน กระแส ความต้านทาน การต่อตัวต้านทานแบบอนุกรมและขนาน'],
            ['วงจรไฟฟ้าเบื้องต้น', 'การต่อวงจรแสงสว่าง สวิตช์ทางเดียวและสองทาง เต้ารับ และการเดินสายในบ้าน'],
            ['อุปกรณ์อิเล็กทรอนิกส์พื้นฐาน', 'ตัวต้านทาน ตัวเก็บประจุ ไดโอด และทรานซิสเตอร์ การอ่านค่าและการวัด'],
        ];

        foreach ($lessons as $order => [$title, $summary]) {
            $this->db->insert('lessons', [
                'course_id' => $courseId,
                'title' => $title,
                'summary' => $summary,
                'sort_order' => $order + 1,
                'source' => 'manual',
                'review_status' => 'published',
                'created_by' => $authorId,
                'published_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function seedPendingReviews(int $teacherId, array $courseId, int $endpointId): void
    {
        $items = [
            [$courseId['mecha'], 'quiz', 'แบบทดสอบบทที่ 3 · เซนเซอร์และอุปกรณ์ตรวจจับ', '8 ข้อ · ปรนัย', 'college', '-2 hours'],
            [$courseId['mecha'], 'lesson_plan', 'แผนการจัดการเรียนรู้ หน่วยที่ 4 · ระบบนิวแมติกส์', '6 หน้า · ตามแบบฟอร์ม สอศ.', 'college', '-1 day'],
            [$courseId['elec'], 'lesson', 'ใบความรู้ฉบับอ่านง่าย · ความปลอดภัยในงานไฟฟ้า', 'ปรับจากใบความรู้เดิม', 'byok', '-1 day'],
        ];

        foreach ($items as [$course, $type, $title, $summary, $source, $when]) {
            $jobId = $this->db->insert('ai_jobs', [
                'user_id' => $teacherId,
                'course_id' => $course,
                'kind' => $type === 'lesson_plan' ? 'lesson_plan' : ($type === 'quiz' ? 'quiz' : 'summary'),
                'source' => $source,
                'status' => 'done',
                'prompt' => null,
                'result' => null,
                'duration_ms' => random_int(9000, 42000),
                'queued_at' => date('Y-m-d H:i:s', strtotime($when)),
                'started_at' => date('Y-m-d H:i:s', strtotime($when)),
                'finished_at' => date('Y-m-d H:i:s', strtotime($when . ' +30 seconds')),
            ]);

            // สร้างแบบทดสอบฉบับร่างจริง เพื่อให้กด "ตรวจ" เปิดหน้าตรวจได้ทันที
            $targetId = null;
            if ($type === 'quiz') {
                $targetId = $this->seedDraftQuiz($course, $teacherId, $title);
            }

            $this->db->run(
                'INSERT INTO {ai_generations} (job_id, user_id, target_type, target_id, payload, review_status, created_at)
                 VALUES (?, ?, ?, ?, ?, \'pending\', ?)',
                [
                    $jobId,
                    $teacherId,
                    $type,
                    $targetId,
                    json_encode([
                        'title' => $title,
                        'summary' => $summary,
                        'ai_mode' => $source === 'byok' ? 'โหมดเร็ว' : null,
                        'params' => ['lessons' => [], 'lessonTitles' => ['บทที่ 3 เซนเซอร์และอุปกรณ์ตรวจจับ'], 'count' => 8, 'types' => ['ปรนัย'], 'level' => 'กลาง'],
                    ], JSON_UNESCAPED_UNICODE),
                    date('Y-m-d H:i:s', strtotime($when)),
                ]
            );
        }
    }

    /** สร้างแบบทดสอบฉบับร่างพร้อมข้อสอบตัวอย่างจากคลังข้อสอบ */
    private function seedDraftQuiz(int $courseId, int $teacherId, string $title): int
    {
        $quizId = $this->db->insert('quizzes', [
            'course_id' => $courseId,
            'title' => $title,
            'attempts_allowed' => 1,
            'source' => 'ai',
            'review_status' => 'draft',
            'created_by' => $teacherId,
        ]);

        $pool = \App\AI\QuestionBank::pick('20127-2002', ['เซนเซอร์และอุปกรณ์ตรวจจับ', 'ระบบนิวแมติกส์เบื้องต้น']);
        $keys = ['ก', 'ข', 'ค', 'ง'];

        foreach (array_slice($pool, 0, 8) as $order => $q) {
            $qid = $this->db->insert('quiz_questions', [
                'quiz_id' => $quizId,
                'type' => $q['type'],
                'question' => $q['question'],
                'explanation' => $q['explanation'],
                'score' => $q['type'] === 'short_answer' ? 2.0 : 1.0,
                'sort_order' => $order,
                'source' => 'ai',
            ]);
            foreach ($q['choices'] as $ci => $text) {
                $this->db->insert('quiz_choices', [
                    'question_id' => $qid,
                    'label' => $keys[$ci] ?? '',
                    'content' => $text,
                    'is_correct' => $ci === $q['answer'] ? 1 : 0,
                    'sort_order' => $ci,
                ]);
            }
        }

        return $quizId;
    }
}
