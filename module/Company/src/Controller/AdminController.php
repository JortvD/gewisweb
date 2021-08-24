<?php

namespace Company\Controller;

use Company\Form\EditCompany as EditCompanyForm;
use Company\Mapper\Label as LabelMapper;
use Company\Service\{
    AclService,
    Company as CompanyService,
    CompanyQuery as CompanyQueryService,
};
use DateInterval;
use DateTime;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\I18n\Translator;
use Laminas\View\Model\ViewModel;
use User\Permissions\NotAllowedException;

class AdminController extends AbstractActionController
{
    /**
     * @var CompanyService
     */
    private CompanyService $companyService;

    /**
     * @var CompanyQueryService
     */
    private CompanyQueryService $companyQueryService;

    /**
     * @var LabelMapper
     */
    private LabelMapper $labelMapper;

    /**
     * @var EditCompanyForm
     */
    private EditCompanyForm $companyForm;

    /**
     * @var array
     */
    private array $languages;

    /**
     * @var AclService
     */
    private AclService $aclService;

    /**
     * @var Translator
     */
    private Translator $translator;

    /**
     * AdminController constructor.
     *
     * @param CompanyService $companyService
     * @param CompanyQueryService $companyQueryService
     * @param LabelMapper $labelMapper
     * @param EditCompanyForm $companyForm
     * @param array $languages
     * @param AclService $aclService
     * @param Translator $translator
     */
    public function __construct(
        CompanyService $companyService,
        CompanyQueryService $companyQueryService,
        LabelMapper $labelMapper,
        EditCompanyForm $companyForm,
        array $languages,
        AclService $aclService,
        Translator $translator
    ) {
        $this->companyService = $companyService;
        $this->companyQueryService = $companyQueryService;
        $this->labelMapper = $labelMapper;
        $this->companyForm = $companyForm;
        $this->languages = $languages;
        $this->aclService = $aclService;
        $this->translator = $translator;
    }

    /**
     * Action that displays the main page.
     */
    public function indexAction()
    {
        if (!$this->aclService->isAllowed('listAllLabels', 'company')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to access the admin interface')
            );
        }

        // Initialize the view
        return new ViewModel(
            [
                'companyList' => $this->companyService->getHiddenCompanyList(),
                'categoryList' => $this->companyQueryService->getCategoryList(false),
                'labelList' => $this->companyQueryService->getLabelList(false),
                'packageFuture' => $this->companyService->getPackageChangeEvents(
                    (new DateTime())->add(
                        new DateInterval('P1M')
                    )
                ),
            ]
        );
    }

    /**
     * Action that allows adding a company.
     */
    public function addCompanyAction()
    {
        if (!$this->aclService->isAllowed('insert', 'company')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to create a company'));
        }

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            // Check if data is valid, and insert when it is
            $company = $this->companyService->insertCompanyByData(
                $request->getPost(),
                $request->getFiles(),
            );

            if (false !== $company) {
                // Redirect to edit page
                return $this->redirect()->toRoute(
                    'admin_company/default',
                    [
                        'action' => 'edit',
                        'slugCompanyName' => $company->getSlugName(),
                    ]
                );
            }
        }

        // The form was not valid, or we did not get data back
        // Initialize the form
        $this->companyForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/default',
                ['action' => 'addCompany']
            )
        );

        // Initialize the view
        return new ViewModel(
            [
                'form' => $this->companyForm,
            ]
        );
    }

    /**
     * Action that allows adding a package.
     */
    public function addPackageAction()
    {
        if (!$this->aclService->isAllowed('edit', 'company')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to edit jobs'));
        }

        // Get parameter
        $companyName = $this->params('slugCompanyName');
        $type = $this->params('type');

        // Get form
        $packageForm = $this->companyService->getPackageForm($type);

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            if (
                $this->companyService->insertPackageForCompanySlugNameByData(
                    $companyName,
                    $request->getPost(),
                    $request->getFiles(),
                    $type
                )
            ) {
                // Redirect to edit page
                return $this->redirect()->toRoute(
                    'admin_company/editCompany',
                    ['slugCompanyName' => $companyName],
                    [],
                    false
                );
            }
        }

        // The form was not valid, or we did not get data back

        // Initialize the form
        $packageForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/editCompany/addPackage',
                ['slugCompanyName' => $companyName, 'type' => $type]
            )
        );

        // Initialize the view
        return new ViewModel(
            [
                'form' => $packageForm,
                'type' => $type,
            ]
        );
    }

    /**
     * Action that allows adding a job.
     */
    public function addJobAction()
    {
        // Get useful stuff
        $companyForm = $this->companyService->getJobForm();

        // Get parameters
        $companyName = $this->params('slugCompanyName');
        $packageId = $this->params('packageId');

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            // Check if data is valid, and insert when it is
            $job = $this->companyService->createJob(
                $packageId,
                $request->getPost(),
                $request->getFiles()
            );

            if ($job) {
                // Redirect to edit page
                return $this->redirect()->toRoute(
                    'admin_company/editCompany/editPackage',
                    [
                        'slugCompanyName' => $companyName,
                        'packageId' => $packageId,
                    ]
                );
            }
        }

        // The form was not valid, or we did not get data back

        // Initialize the form
        $companyForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/editCompany/editPackage/addJob',
                [
                    'slugCompanyName' => $companyName,
                    'packageId' => $packageId,
                ]
            )
        );

        // Initialize the view
        $vm = new ViewModel(
            [
                'form' => $companyForm,
                'languages' => $this->getLanguageDescriptions(),
            ]
        );

        $vm->setTemplate('company/admin/edit-job');

        return $vm;
    }

    /**
     * Action that displays a form for editing a category.
     */
    public function editCategoryAction()
    {
        if (!$this->aclService->isAllowed('edit', 'company')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to edit job categories'));
        }

        // Get useful stuff
        $categoryForm = $this->companyService->getCategoryForm();

        // Get parameter
        $languageNeutralId = $this->params('languageNeutralCategoryId');
        if (null === $languageNeutralId) {
            // The parameter is invalid or non-existent
            return $this->notFoundAction();
        }

        // Get the specified category
        $categories = $this->companyService->getAllCategoriesById($languageNeutralId);

        // If the category is not found, throw 404
        if (empty($categories)) {
            return $this->notFoundAction();
        }

        // Handle incoming form data
        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();
            $categoryDict = [];

            foreach ($categories as $category) {
                $categoryDict[$category->getLanguage()] = $category;
            }

            $this->companyService->saveCategoryData($languageNeutralId, $categoryDict, $post);
        }

        // Initialize form
        $categoryDict = [];
        foreach ($categories as $category) {
            $categoryDict[$category->getLanguage()] = $category;
        }

        $languages = array_keys($categoryDict);
        $categoryForm->setLanguages($languages);
        $categoryForm->bind($categoryDict);

        return new ViewModel(
            [
                'form' => $categoryForm,
                'category' => $categories,
                'languages' => $this->getLanguageDescriptions(),
            ]
        );
    }

    /**
     * Action that displays a form for editing a label.
     * @noinspection PhpParamsInspection
     */
    public function editLabelAction()
    {
        // Get useful stuff
        $labelForm = $this->companyService->getLabelForm();

        // Get parameter
        $languageNeutralId = $this->params('languageNeutralLabelId');
        if (null === $languageNeutralId) {
            // The parameter is invalid or non-existent
            return $this->notFoundAction();
        }

        // Get the specified label
        $labels = $this->companyService->getAllLabelsById($languageNeutralId);

        // If the label is not found, throw 404
        if (empty($labels)) {
            return $this->notFoundAction();
        }

        // Handle incoming form data
        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();
            $labelDict = [];

            foreach ($labels as $label) {
                $labelDict[$label->getLanguage()] = $label;
            }

            $this->companyService->saveLabelData($languageNeutralId, $labelDict, $post);
        }

        // Initialize form
        $labelDict = [];
        foreach ($labels as $label) {
            $labelDict[$label->getLanguage()] = $label;
        }

        $languages = array_keys($labelDict);
        $labelForm->setLanguages($languages);
        $labelForm->bind($labelDict);

        return new ViewModel(
            [
                'form' => $labelForm,
                'label' => $labels,
                'languages' => $this->getLanguageDescriptions(),
            ]
        );
    }

    private function getLanguageDescriptions()
    {
        $languages = $this->languages;
        $languageDictionary = [];
        foreach ($languages as $key) {
            $languageDictionary[$key] = $this->companyService->getLanguageDescription($key);
        }

        return $languageDictionary;
    }

    /**
     * Action that displays a form for editing a company.
     */
    public function editCompanyAction()
    {
        // Get useful stuff
        $companyForm = $this->companyForm;

        // Get parameter
        $companyName = $this->params('slugCompanyName');

        // Get the specified company
        $companyList = $this->companyService->getEditableCompaniesBySlugName($companyName);

        // If the company is not found, throw 404
        if (empty($companyList)) {
            return $this->notFoundAction();
        }

        $company = $companyList[0];

        // Handle incoming form data
        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();

            if (
                $this->companyService->saveCompanyByData(
                    $company,
                    $post,
                    $request->getFiles(),
                )
            ) {
                $companyName = $request->getPost()['slugName'];

                return $this->redirect()->toRoute(
                    'admin_company/default',
                    [
                        'action' => 'edit',
                        'slugCompanyName' => $companyName,
                    ],
                    [],
                    false
                );
            }
        }

        // Initialize form
        $companyForm->setData($company->getArrayCopy());
        $companyForm->get('languages')->setValue($company->getArrayCopy()['languages']);
        $companyForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/default',
                [
                    'action' => 'editCompany',
                    'slugCompanyName' => $companyName,
                ]
            )
        );

        return new ViewModel(
            [
                'company' => $company,
                'form' => $companyForm,
            ]
        );
    }

    /**
     * Action that displays a form for editing a package.
     */
    public function editPackageAction()
    {
        // Get the parameters
        $companyName = $this->params('slugCompanyName');
        $packageId = $this->params('packageId');

        // Get the specified package (Assuming it is found)
        $package = $this->companyService->getEditablePackage($packageId);
        $type = $package->getType();

        // Get form
        $packageForm = $this->companyService->getPackageForm($type);

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            if ($this->companyService->savePackageByData($package, $request->getPost(), $request->getFiles())) {
                // TODO: possibly redirect to company
            }
        }
        // TODO: display error page when package is not found

        // Initialize form
        $packageForm->bind($package);
        $packageForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/editCompany/editPackage',
                [
                    'packageId' => $packageId,
                    'slugCompanyName' => $companyName,
                    'type' => $type,
                ]
            )
        );

        // Initialize the view
        return new ViewModel(
            [
                'package' => $package,
                'companyName' => $companyName,
                'form' => $packageForm,
                'type' => $type,
            ]
        );
    }

    /**
     * Action that displays a form for editing a job.
     */
    public function editJobAction()
    {
        // Get useful stuff
        $jobForm = $this->companyService->getJobForm();

        // Get the parameters
        $languageNeutralId = $this->params('languageNeutralJobId');

        // Find the specified jobs
        $jobs = $this->companyService->getEditableJobsByLanguageNeutralId($languageNeutralId);

        // Check the job is found. If not, throw 404
        if (empty($jobs)) {
            return $this->notFoundAction();
        }

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            $files = $request->getFiles();
            $post = $request->getPost();
            $jobDict = [];

            foreach ($jobs as $job) {
                $jobDict[$job->getLanguage()] = $job;
            }

            $this->companyService->saveJobData($languageNeutralId, $jobDict, $post, $files);
        }

        // Initialize the form
        $jobDict = [];
        foreach ($jobs as $job) {
            $jobDict[$job->getLanguage()] = $job;
        }
        $languages = array_keys($jobDict);
        $jobForm->setLanguages($languages);

        $labels = $jobs[0]->getLabels();

        $mapper = $this->labelMapper;
        $actualLabels = [];
        foreach ($labels as $label) {
            $actualLabel = $label->getLabel();
            $actualLabels[] = $mapper->siblingLabel($actualLabel, 'en');
            $actualLabels[] = $mapper->siblingLabel($actualLabel, 'nl');
        }
        $jobForm->setLabels($actualLabels);
        $jobForm->bind($jobDict);

        // Initialize the view
        return new ViewModel(
            [
                'form' => $jobForm,
                // TODO: Only attachment of one language of the job is passed on to the view (view needs adjustment)
                'job' => $jobs[0],
                'languages' => $this->getLanguageDescriptions(),
            ]
        );
    }

    /**
     * Action that first asks for confirmation, and when given, deletes the company.
     */
    public function deleteCompanyAction()
    {
        // Get parameters
        $slugName = $this->params('slugCompanyName');

        // Handle incoming form data
        $request = $this->getRequest();
        if ($request->isPost()) {
            $this->companyService->deleteCompaniesBySlug($slugName);

            return $this->redirect()->toRoute('admin_company');
        }

        return $this->notFoundAction();
    }

    public function addCategoryAction()
    {
        // Get useful stuff
        $categoryForm = $this->companyService->getCategoryForm();

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            // Check if data is valid, and insert when it is
            $category = $this->companyService->createCategory($request->getPost());

            if (is_numeric($category)) {
                // Redirect to edit page
                return $this->redirect()->toRoute(
                    'admin_company/editCategory',
                    [
                        'languageNeutralCategoryId' => $category,
                    ]
                );
            }
        }

        // The form was not valid, or we did not get data back

        // Initialize the form
        $categoryForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/default',
                ['action' => 'addCategory']
            )
        );
        // Initialize the view
        return new ViewModel(
            [
                'form' => $categoryForm,
                'languages' => $this->getLanguageDescriptions(),
            ]
        );
    }

    public function addLabelAction()
    {
        // Get useful stuff
        $labelForm = $this->companyService->getLabelForm();

        // Handle incoming form results
        $request = $this->getRequest();
        if ($request->isPost()) {
            // Check if data is valid, and insert when it is
            $label = $this->companyService->createLabel($request->getPost());

            if (is_numeric($label)) {
                // Redirect to edit page
                return $this->redirect()->toRoute(
                    'admin_company/editLabel',
                    [
                        'languageNeutralLabelId' => $label,
                    ]
                );
            }
        }

        // The form was not valid, or we did not get data back

        // Initialize the form
        $labelForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin_company/default',
                ['action' => 'addLabel']
            )
        );
        // Initialize the view
        return new ViewModel(
            [
                'form' => $labelForm,
                'languages' => $this->getLanguageDescriptions(),
            ]
        );
    }

    /**
     * Action that first asks for confirmation, and when given, deletes the Package.
     */
    public function deletePackageAction()
    {
        // Get parameters
        $packageId = $this->params('packageId');
        $companyName = $this->params('slugCompanyName');

        // Handle incoming form data
        $request = $this->getRequest();
        if ($request->isPost()) {
            $this->companyService->deletePackage($packageId);

            return $this->redirect()->toRoute(
                'admin_company/editCompany',
                ['slugCompanyName' => $companyName]
            );
        }

        return $this->notFoundAction();
    }

    /**
     * Action to delete a job.
     */
    public function deleteJobAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->notFoundAction();
        }

        $jobId = $this->params('languageNeutralJobId');

        $this->companyService->deleteJob($jobId);

        $companyName = $this->params('slugCompanyName');
        $packageId = $this->params('packageId');

        // Redirect to package page
        return $this->redirect()->toRoute(
            'admin_company/editCompany/editPackage',
            [
                'slugCompanyName' => $companyName,
                'packageId' => $packageId,
            ]
        );
    }
}
