<?php

namespace Education\Service;

use Application\Service\AbstractAclService;

use Education\Model\Course as CourseModel;
use Education\Model\Exam as ExamModel;
use Education\Model\Summary as SummaryModel;

use Zend\Form\FormInterface;

/**
 * Exam service.
 */
class Exam extends AbstractAclService
{

    /**
     * Bulk form.
     *
     * @var \Education\Form\Bulk
     */
    protected $bulkForm;

    /**
     * Search for a course.
     *
     * @param array $data
     *
     * @return array Courses, null if form is not valid
     */
    public function searchCourse($data)
    {
        $form = $this->getSearchCourseForm();
        $form->setData($data);

        if (!$form->isValid()) {
            return null;
        }

        $data = $form->getData();
        $query = $data['query'];

        return $this->getCourseMapper()->search($query);
    }

    /**
     * Get a course
     *
     * @param string $code
     *
     * @return CourseModel
     */
    public function getCourse($code)
    {
        return $this->getCourseMapper()->findByCode($code);
    }

    /**
     * Get an exam
     *
     * @param int $id
     *
     * @return ExamModel
     */
    public function getExamDownload($id)
    {
        $exam = $this->getExamMapper()->find($id);

        return $this->getFileStorageService()
            ->downloadFile($exam->getFilename(), $this->examToFilename($exam));
    }

    /**
     * Finish the bulk edit.
     *
     * @param array $data POST Data
     *
     * @return boolean
     */
    public function bulkEdit($data)
    {
        $form = $this->getBulkForm();

        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $data = $form->getData();

        $storageService = $this->getFileStorageService();
        $config = $this->getConfig('education_temp');

        /**
         * Persist the exams and save the uploaded files.
         *
         * We do this in a transactional block, so if there is something
         * wrong, we only have to throw an exception and Doctrine will roll
         * back the transaction. This comes in handy if we are somehow unable
         * to process the upload. This does allow us to get the ID of the
         * exam, which we need in the upload process.
         */

        $this->getExamMapper()->transactional(function ($mapper) use ($data, $config, $storageService) {
            foreach ($data['exams'] as $examData) {
                // finalize exam upload
                $exam = new ExamModel();
                $exam->setDate(new \DateTime($examData['date']));
                $exam->setCourse($this->getCourse($examData['course']));

                $localFile = $config['upload_dir'] . '/' . $examData['file'];

                $exam->setFilename($storageService->storeFile($localFile));

                $mapper->persist($exam);
            }
        });

        return true;
    }

    /**
     * Temporary exam upload.
     *
     * Uploads exams into a temporary folder.
     *
     * @param array $post POST Data
     * @param array $files FILES Data
     *
     * @return boolean
     */
    public function tempUpload($post, $files)
    {
        $form = $this->getTempUploadForm();

        $data = array_merge_recursive($post->toArray(), $files->toArray());

        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $config = $this->getConfig('education_temp');

        $filename = $data['file']['name'];
        $path = $config['upload_dir'] . '/' . $filename;

        if (!file_exists($path)) {
            move_uploaded_file($data['file']['tmp_name'], $path);
        }
        return true;
    }

    /**
     * Upload a new exam.
     *
     * @param array $post POST Data
     * @param array $files FILES Data
     *
     * @return boolean
     */
    public function upload($post, $files)
    {
        $form = $this->getUploadForm();
        $form->bind(new ExamModel());

        $data = array_merge_recursive($post->toArray(), $files->toArray());

        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $exam = $form->getData();
        $data = $form->getData(FormInterface::VALUES_AS_ARRAY);

        $storageService = $this->getFileStorageService();

        /**
         * Save the uploaded file and persist the exam.
         *
         * We do this in a transactional block, so if there is something
         * wrong, we only have to throw an exception and Doctrine will roll
         * back the transaction. This comes in handy if we are somehow unable
         * to process the upload. This does allow us to get the ID of the
         * exam, which we need in the upload process.
         */
        $this->getExamMapper()->transactional(function ($mapper) use ($exam, $data, $storageService) {
            $exam->setFilename($storageService->storeUploadedFile($data['upload']));

            $mapper->persist($exam);
        });

        return true;
    }

    /**
     * Get a filename from an exam (or summary).
     *
     * We do this, since we have so many courses, that most filesystems get
     * choked up on the directory size. By dividing it into subdirectories, we
     * get a much better performance from the filesystem.
     *
     * Exams will have a filename of the following format:
     *
     * <code>-exam-<year>-<month>-<day>.pdf
     *
     * Summaries have the following format:
     *
     * <code>-<author>-summary-<year>-<month>-<day>.pdf
     *
     * @param ExamModel $exam
     *
     * @return string Filename
     */
    public function examToFilename(ExamModel $exam)
    {
        $code = $exam->getCourse()->getCode();
        $dir = substr($code, 0, 2) . '/' . substr($code, 2) . '/';

        $filename = [];

        $filename[] = $code;

        if ($exam instanceof SummaryModel) {
            $filename[] = $exam->getAuthor();
            $filename[] = 'summary';
        } else {
            $filename[] = 'exam';
        }

        $filename[] = $exam->getDate()->format('Y-m-d');


        return $dir . implode('-', $filename) . '.pdf';
    }

    /**
     * Get the education config, as used by this service.
     *
     * @return array
     */
    public function getConfig($key = 'education')
    {
        $config = $this->sm->get('config');
        return $config[$key];
    }

    /**
     * Get the Upload form.
     *
     * @return \Education\Form\Upload
     *
     * @throws \User\Permissions\NotAllowedException When not allowed to upload
     */
    public function getUploadForm()
    {
        if (!$this->isAllowed('upload')) {
            $translator = $this->getTranslator();
            throw new \User\Permissions\NotAllowedException(
                $translator->translate('You are not allowed to upload exams')
            );
        }
        return $this->sm->get('education_form_upload');
    }

    /**
     * Get the bulk edit form.
     *
     * @return \Education\Form\Bulk
     *
     * @throws \User\Permissions\NotAllowedException When not allowed to upload
     */
    public function getBulkForm()
    {
        if (!$this->isAllowed('upload')) {
            $translator = $this->getTranslator();
            throw new \User\Permissions\NotAllowedException(
                $translator->translate('You are not allowed to upload exams')
            );
        }
        if (null !== $this->bulkForm) {
            return $this->bulkForm;
        }

        // fully load the bulk form
        $this->bulkForm = $this->sm->get('education_form_bulk');

        $config = $this->getConfig('education_temp');

        $dir = new \DirectoryIterator($config['upload_dir']);
        $data = [];

        foreach ($dir as $file) {
            if ($file->isFile() && substr($file->getFilename(), 0, 1) != '.') {
                $data[] = [
                    'file' => $file->getFilename()
                ];
            }
        }

        $this->bulkForm->get('exams')->populateValues($data);

        return $this->bulkForm;
    }

    /**
     * Get the Temporary Upload form.
     *
     * @return \Education\Form\TempUpload
     *
     * @throws \User\Permissions\NotAllowedException When not allowed to upload
     */
    public function getTempUploadForm()
    {
        if (!$this->isAllowed('upload')) {
            $translator = $this->getTranslator();
            throw new \User\Permissions\NotAllowedException(
                $translator->translate('You are not allowed to upload exams')
            );
        }
        return $this->sm->get('education_form_tempupload');
    }

    /**
     * Get the storage service.
     *
     * @return \Application\Service\FileStorage
     */
    public function getFileStorageService()
    {
        return $this->sm->get('application_service_storage');
    }

    /**
     * Get the SearchExam form.
     *
     * @return \Education\Form\SearchCourse
     */
    public function getSearchCourseForm()
    {
        return $this->sm->get('education_form_searchcourse');
    }

    /**
     * Get the course mapper.
     *
     * @return \Education\Mapper\Course
     */
    public function getCourseMapper()
    {
        return $this->getServiceManager()->get('education_mapper_course');
    }

    /**
     * Get the exam mapper.
     *
     * @return \Education\Mapper\Exam
     */
    public function getExamMapper()
    {
        return $this->sm->get('education_mapper_exam');
    }

    /**
     * Get the Acl.
     *
     * @return Zend\Permissions\Acl\Acl
     */
    public function getAcl()
    {
        return $this->getServiceManager()->get('education_acl');
    }

    /**
     * Get the default resource ID.
     *
     * @return string
     */
    protected function getDefaultResourceId()
    {
        return 'exam';
    }
}
