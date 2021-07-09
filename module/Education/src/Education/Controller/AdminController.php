<?php

namespace Education\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\View\Model\ViewModel;

class AdminController extends AbstractActionController
{

    /**
     * @var \Education\Service\Exam
     */
    private $examService;

    public function __construct(\Education\Service\Exam $examService)
    {
        $this->examService = $examService;
    }

    public function indexAction()
    {
    }

    public function addCourseAction()
    {
        $request = $this->getRequest();

        if ($request->isPost()) {
            // try uploading
            if ($this->examService->addCourse($request->getPost())) {
                $this->getResponse()->setStatusCode(200);
                return new ViewModel([
                    'form' => $this->examService->getAddCourseForm(),
                    'success' => true
                ]);
            }
            $this->getResponse()->setStatusCode(400);
            return new ViewModel([
                'form' => $this->examService->getAddCourseForm(),
                'success' => false
            ]);
        }

        return new ViewModel([
            'form' => $this->examService->getAddCourseForm()
        ]);
    }

    public function bulkExamAction()
    {
        $request = $this->getRequest();

        if ($request->isPost()) {
            // try uploading
            if ($this->examService->tempExamUpload($request->getPost(), $request->getFiles())) {
                return new ViewModel([
                    'success' => true
                ]);
            } else {
                $this->getResponse()->setStatusCode(500);
                return new ViewModel([
                    'success' => false
                ]);
            }
        }

        return new ViewModel([
            'form' => $this->examService->getTempUploadForm()
        ]);
    }

    public function bulkSummaryAction()
    {
        $request = $this->getRequest();

        if ($request->isPost()) {
            // try uploading
            if ($this->examService->tempSummaryUpload($request->getPost(), $request->getFiles())) {
                return new ViewModel([
                    'success' => true
                ]);
            } else {
                $this->getResponse()->setStatusCode(500);
                return new ViewModel([
                    'success' => false
                ]);
            }
        }

        return new ViewModel([
            'form' => $this->examService->getTempUploadForm()
        ]);
    }

    /**
     * Edit several exams in bulk.
     */
    public function editExamAction()
    {
        $request = $this->getRequest();

        if ($request->isPost() && $this->examService->bulkExamEdit($request->getPost())) {
            return new ViewModel([
                'success' => true
            ]);
        }

        $config = $this->getServiceLocator()->get('config');
        $config = $config['education_temp'];

        return new ViewModel([
            'form' => $this->examService->getBulkExamForm(),
            'config' => $config
        ]);
    }

    /**
     * Edit summaries in bulk.
     */
    public function editSummaryAction()
    {
        $request = $this->getRequest();

        if ($request->isPost() && $this->examService->bulkSummaryEdit($request->getPost())) {
            return new ViewModel([
                'success' => true
            ]);
        }

        $config = $this->getServiceLocator()->get('config');
        $config = $config['education_temp'];

        return new ViewModel([
            'form' => $this->examService->getBulkSummaryForm(),
            'config' => $config
        ]);
    }

    public function summaryAction()
    {
        $request = $this->getRequest();

        if ($request->isPost()) {
            // try uploading
            if ($this->examService->uploadSummary($request->getPost(), $request->getFiles())) {
                return new ViewModel([
                    'success' => true
                ]);
            }
        }

        return new ViewModel([
            'form' => $this->examService->getSummaryUploadForm()
        ]);
    }

    public function deleteTempAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            $this->examService->deleteTempExam($this->params()->fromRoute('filename'), $this->params()->fromRoute('type'));
            return new JsonModel(['success' => 'true']);
        }
        return $this->notFoundAction();
    }
}
