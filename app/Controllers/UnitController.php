<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * CRUD สำหรับหน่วยการเรียนและส่วนเนื้อหาภายในหน่วย
 * ครูเจ้าของรายวิชาเท่านั้นที่แก้ไขได้
 */
final class UnitController
{
    private const PDF_MIME = ['application/pdf'];
    private const MAX_PDF_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
    ) {
    }

    /** แบบฟอร์มสร้าง/แก้ไขหน่วยการเรียน */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $course = $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $unitId = isset($args['id']) ? (int) $args['id'] : 0;
        $unit = $unitId > 0 ? $this->units->find($unitId) : null;

        if ($unitId > 0 && ($unit === null || (int) $unit['course_id'] !== $courseId)) {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }

        $sections = $unit ? $this->units->sectionsFor($unitId) : [];

        return $this->view->render($response, 'courses/unit-edit', [
            'page' => 'courses',
            'course' => $course,
            'unit' => $unit,
            'sections' => $sections,
        ]);
    }

    /** บันทึกหน่วยการเรียน (สร้างหรือแก้ไข) */
    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirectToCourse($response, $courseId);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            Flash::error('กรุณากรอกชื่อหน่วยการเรียน');

            return $this->redirectToCourse($response, $courseId);
        }

        $fields = [
            'title'        => $title,
            'key_content'  => trim((string) ($data['key_content'] ?? '')) ?: null,
            'objectives'   => trim((string) ($data['objectives'] ?? '')) ?: null,
            'competencies' => trim((string) ($data['competencies'] ?? '')) ?: null,
            'review_status' => ($data['action'] ?? '') === 'publish' ? 'published' : 'draft',
        ];

        $unitId = (int) ($args['id'] ?? 0);
        if ($unitId > 0) {
            $unit = $this->units->find($unitId);
            if ($unit === null || (int) $unit['course_id'] !== $courseId) {
                throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
            }
            if ($fields['review_status'] === 'published' && $unit['published_at'] === null) {
                $fields['published_at'] = date('Y-m-d H:i:s');
            }
            $this->units->update($unitId, $fields);
            Flash::success('บันทึกหน่วยการเรียนแล้ว');
        } else {
            $unitId = $this->units->create($fields + [
                'course_id'  => $courseId,
                'sort_order' => $this->units->nextSortOrder($courseId),
                'source'     => 'manual',
                'created_by' => (int) $user['id'],
                'published_at' => $fields['review_status'] === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
            Flash::success('เพิ่มหน่วยการเรียนแล้ว');
        }

        return $this->redirectToUnit($response, $courseId, $unitId);
    }

    /** เพิ่มส่วนเนื้อหา (text / video / pdf) ลงในหน่วย */
    public function addSection(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $unitId = (int) $args['id'];
        $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $unit = $this->units->find($unitId);
        if ($unit === null || (int) $unit['course_id'] !== $courseId) {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirectToUnit($response, $courseId, $unitId);
        }

        $type = in_array($data['type'] ?? '', ['text', 'video', 'pdf'], true) ? $data['type'] : 'text';
        $title = trim((string) ($data['title'] ?? ''));

        $sectionData = [
            'unit_id'    => $unitId,
            'type'       => $type,
            'title'      => $title !== '' ? $title : match($type) {
                'text'  => 'เนื้อหา',
                'video' => 'วีดิโอ',
                'pdf'   => 'เอกสาร PDF',
            },
            'sort_order' => $this->units->nextSectionSortOrder($unitId),
        ];

        if ($type === 'text') {
            $sectionData['content'] = trim((string) ($data['content'] ?? '')) ?: null;
            $this->units->createSection($sectionData);
            Flash::success('เพิ่มส่วนเนื้อหาแล้ว');
        } elseif ($type === 'video') {
            $url = trim((string) ($data['video_url'] ?? ''));
            if ($url === '') {
                Flash::error('กรุณากรอก URL วีดิโอ');

                return $this->redirectToUnit($response, $courseId, $unitId);
            }
            $sectionData['video_url'] = $url;
            $this->units->createSection($sectionData);
            Flash::success('เพิ่มส่วนวีดิโอแล้ว');
        } elseif ($type === 'pdf') {
            $files = $request->getUploadedFiles();
            $file = $files['pdf_file'] ?? null;

            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                Flash::error('กรุณาเลือกไฟล์ PDF');

                return $this->redirectToUnit($response, $courseId, $unitId);
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                Flash::error('อัปโหลดไฟล์ไม่สำเร็จ');

                return $this->redirectToUnit($response, $courseId, $unitId);
            }
            if ($file->getSize() > self::MAX_PDF_BYTES) {
                Flash::error('ไฟล์ใหญ่เกิน 30 MB');

                return $this->redirectToUnit($response, $courseId, $unitId);
            }
            $mime = (string) $file->getClientMediaType();
            $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
            if (!in_array($mime, self::PDF_MIME, true) && $ext !== 'pdf') {
                Flash::error('รองรับเฉพาะไฟล์ PDF');

                return $this->redirectToUnit($response, $courseId, $unitId);
            }

            $dir = Paths::storage('uploads/units/' . $unitId);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $stored = bin2hex(random_bytes(8)) . '.pdf';
            $file->moveTo($dir . '/' . $stored);

            $sectionData['file_path'] = 'units/' . $unitId . '/' . $stored;
            $sectionData['file_name'] = mb_substr((string) $file->getClientFilename(), 0, 255);
            $this->units->createSection($sectionData);
            Flash::success('เพิ่มเอกสาร PDF แล้ว');
        }

        return $this->redirectToUnit($response, $courseId, $unitId);
    }

    /** ลบส่วนเนื้อหา */
    public function deleteSection(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $unitId = (int) $args['id'];
        $sectionId = (int) $args['sectionId'];
        $this->requireOwnedCourse($request, $courseId, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirectToUnit($response, $courseId, $unitId);
        }

        $section = $this->units->findSection($sectionId);
        if ($section !== null && (int) $section['unit_id'] === $unitId) {
            $this->units->deleteSection($sectionId);
            Flash::success('ลบส่วนเนื้อหาแล้ว');
        }

        return $this->redirectToUnit($response, $courseId, $unitId);
    }

    /** @return array<string,mixed> */
    private function requireOwnedCourse(Request $request, int $courseId, int $teacherId): array
    {
        $course = $this->courses->find($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== $teacherId) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        return $course;
    }

    private function redirectToCourse(Response $response, int $courseId): Response
    {
        return $response->withHeader('Location', Url::to("/courses/$courseId?tab=units"))->withStatus(302);
    }

    private function redirectToUnit(Response $response, int $courseId, int $unitId): Response
    {
        return $response->withHeader('Location', Url::to("/courses/$courseId/units/$unitId/edit"))->withStatus(302);
    }
}
