<?php

namespace Activity\Service;

use Activity\Form\Activity as ActivityForm;
use Activity\Model\Activity as ActivityModel;
use Activity\Model\ActivityUpdateProposal as ActivityProposalModel;
use Activity\Model\LocalisedText;
use Activity\Model\SignupField as SignupFieldModel;
use Activity\Model\SignupList as SignupListModel;
use Activity\Model\SignupOption as SignupOptionModel;
use Application\Service\Email;
use Company\Service\Company;
use DateTime;
use Decision\Model\Organ;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Laminas\Mvc\I18n\Translator;
use Laminas\Stdlib\Parameters;
use User\Model\User;
use User\Permissions\NotAllowedException;

class Activity
{
    /**
     * @var Translator
     */
    private $translator;
    /**
     * @var EntityManager
     */
    private $entityManager;
    /**
     * @var ActivityCategory
     */
    private $categoryService;
    /**
     * @var \Decision\Service\Organ
     */
    private $organService;
    /**
     * @var Company
     */
    private $companyService;
    /**
     * @var Email
     */
    private $emailService;
    /**
     * @var ActivityForm
     */
    private $activityForm;
    private AclService $aclService;

    public function __construct(
        Translator $translator,
        EntityManager $entityManager,
        ActivityCategory $categoryService,
        \Decision\Service\Organ $organService,
        Company $companyService,
        Email $emailService,
        ActivityForm $activityForm,
        AclService $aclService
    ) {
        $this->translator = $translator;
        $this->entityManager = $entityManager;
        $this->categoryService = $categoryService;
        $this->organService = $organService;
        $this->companyService = $companyService;
        $this->emailService = $emailService;
        $this->activityForm = $activityForm;
        $this->aclService = $aclService;
    }

    /**
     * Create an activity from the creation form.
     *
     * @pre $params is valid data of Activity\Form\Activity
     *
     * @param array $data Parameters describing activity
     *
     * @return bool activity that was created
     */
    public function createActivity($data)
    {
        if (!$this->aclService->isAllowed('create', 'activity')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to create an activity'));
        }

        $form = $this->getActivityForm();
        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        // Find the creator
        $user = $this->aclService->getIdentityOrThrowException();

        // Find the organ the activity belongs to, and see if the user has permission to create an activity
        // for this organ. If the id is 0, the activity belongs to no organ.
        $organId = intval($data['organ']);
        $organ = null;

        if (0 !== $organId) {
            $organ = $this->findOrgan($organId);
        }

        // Find the company the activity belongs to. If the id is 0, the activity belongs to no company.
        $companyId = intval($data['company']);
        $company = null;

        if (0 !== $companyId) {
            $company = $this->companyService->getCompanyById($companyId);
        }

        $activity = $this->saveActivityData($data, $user, $organ, $company, ActivityModel::STATUS_TO_APPROVE);

        // Send email to GEFLITST if user checked checkbox of GEFLITST
        if ($activity->getRequireGEFLITST()) {
            $this->requestGEFLITST($activity, $user, $organ);
        }

        return true;
    }

    /**
     * Return activity creation form.
     *
     * @return ActivityForm
     */
    public function getActivityForm()
    {
        if (!$this->aclService->isAllowed('create', 'activity')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to create an activity'));
        }

        return $this->activityForm;
    }

    /**
     * Find the organ the activity belongs to, and see if the user has permission to create an activity
     * for this organ.
     *
     * @param int $organId The id of the organ associated with the activity
     *
     * @return Organ The organ associated with the activity, if the user is a member of that organ
     *
     * @throws NotAllowedException if the user is not a member of the organ specified
     */
    protected function findOrgan($organId)
    {
        $organ = $this->organService->getOrgan($organId);

        if (!$this->organService->canEditOrgan($organ)) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to create an activity for this organ')
            );
        }

        return $organ;
    }

    /**
     * Create an activity from parameters.
     *
     * @pre $data is valid data of Activity\Form\Activity
     *
     * @param array $data Parameters describing activity
     * @param User $user The user that creates this activity
     * @param Organ $organ The organ this activity is associated with
     * @param \Company\Model\Company|null $company The company this activity is associated with
     * @param int $status
     *
     * @return ActivityModel activity that was created
     */
    protected function saveActivityData($data, $user, $organ, $company, $status)
    {
        $activity = new ActivityModel();
        $activity->setBeginTime(new DateTime($data['beginTime']));
        $activity->setEndTime(new DateTime($data['endTime']));

        $activity->setName(new LocalisedText($data['nameEn'], $data['name']));
        $activity->setLocation(new LocalisedText($data['locationEn'], $data['location']));
        $activity->setCosts(new LocalisedText($data['costsEn'], $data['costs']));
        $activity->setDescription(new LocalisedText($data['descriptionEn'], $data['description']));

        $activity->setIsMyFuture($data['isMyFuture']);
        $activity->setRequireGEFLITST($data['requireGEFLITST']);

        // Not user provided input
        $activity->setCreator($user);
        $activity->setOrgan($organ);
        $activity->setCompany($company);
        $activity->setStatus($status);

        $em = $this->entityManager;

        if (isset($data['categories'])) {
            foreach ($data['categories'] as $category) {
                $category = $this->categoryService->getCategoryById($category);

                if (!is_null($category)) {
                    $activity->addCategory($category);
                }
            }
        }

        if (isset($data['signupLists'])) {
            foreach ($data['signupLists'] as $signupList) {
                // Laminas\Stdlib\Parameters is required to prevent undefined indices.
                $signupList = $this->createSignupList(new Parameters($signupList), $activity);
                $em->persist($signupList);
            }
            $em->flush();
        }

        $em->persist($activity);
        $em->flush();

        // Send an email when a new Activity was created, but do not send one
        // when an activity is updated. This e-mail is handled in
        // `$this->createUpdateProposal()`.
        if (ActivityModel::STATUS_UPDATE !== $status) {
            $this->emailService->sendEmail(
                'activity_creation',
                'email/activity',
                'Nieuwe activiteit aangemaakt op de GEWIS website | New activity was created on the GEWIS website',
                ['activity' => $activity]
            );
        }

        return $activity;
    }

    /**
     * Creates a SignupList for the specified Activity.
     *
     * @param array|Parameters $data
     * @param ActivityModel $activity
     *
     * @return SignupListModel
     */
    public function createSignupList($data, $activity)
    {
        $signupList = new SignupListModel();

        $signupList->setActivity($activity);
        $signupList->setName(new LocalisedText($data['nameEn'], $data['name']));
        $signupList->setOpenDate(new DateTime($data['openDate']));
        $signupList->setCloseDate(new DateTime($data['closeDate']));

        $signupList->setOnlyGEWIS($data['onlyGEWIS']);
        $signupList->setDisplaySubscribedNumber($data['displaySubscribedNumber']);

        if (isset($data['fields'])) {
            $em = $this->entityManager;

            foreach ($data['fields'] as $field) {
                // Laminas\Stdlib\Parameters is required to prevent undefined indices.
                $field = $this->createSignupField(new Parameters($field), $signupList);
                $em->persist($field);
            }
            $em->flush();
        }

        return $signupList;
    }

    /**
     * Create a new field.
     *
     * @pre $data is valid data of Activity\Form\SignupListFields
     *
     * @param array|Parameters $data parameters for the new field
     * @param SignupListModel $signupList the SignupList the field belongs to
     *
     * @return SignupFieldModel the new field
     */
    public function createSignupField($data, $signupList)
    {
        $field = new SignupFieldModel();

        $field->setSignupList($signupList);
        $field->setName(new LocalisedText($data['nameEn'], $data['name']));
        $field->setType($data['type']);

        if ('2' === $data['type']) {
            $field->setMinimumValue($data['minimumValue']);
            $field->setMaximumValue($data['maximumValue']);
        }

        if ('3' === $data['type']) {
            $this->createSignupOption($data, $field);
        }

        return $field;
    }

    /**
     * Creates options for both languages specified and adds it to $field.
     * If no languages are specified, this method does nothing.
     *
     * @pre The options corresponding to the languages specified are filled in
     * $params. If both languages are specified, they must have the same amount of options.
     *
     * @param array $data the array containing the options strings
     * @param SignupFieldModel $field the field to add the options to
     */
    protected function createSignupOption($data, $field)
    {
        $numOptions = 0;
        $em = $this->entityManager;

        if (isset($data['options'])) {
            $options = explode(',', $data['options']);
            $options = array_map('trim', $options);
            $numOptions = count($options);
        }

        if (isset($data['optionsEn'])) {
            $optionsEn = explode(',', $data['optionsEn']);
            $optionsEn = array_map('trim', $optionsEn);
            $numOptions = count($optionsEn);
        }

        for ($i = 0; $i < $numOptions; ++$i) {
            $option = new SignupOptionModel();
            $option->setValue(new LocalisedText(
                isset($optionsEn) ? $optionsEn[$i] : null,
                isset($options) ? $options[$i] : null
            ));
            $option->setField($field);
            $em->persist($option);
        }

        $em->flush();
    }

    /**
     * @param ActivityModel $activity
     * @param User $user
     * @param Organ $organ
     */
    private function requestGEFLITST($activity, $user, $organ)
    {
        // Default to an English title, otherwise use the Dutch title
        $activityTitle = $activity->getName()->getText('en');
        $activityTime = $activity->getBeginTime()->format('d-m-Y H:i');

        $type = 'activity_creation_require_GEFLITST';
        $view = 'email/activity_created_require_GEFLITST';

        if (null != $organ) {
            $subject = sprintf('%s: %s on %s', $organ->getAbbr(), $activityTitle, $activityTime);

            $organInfo = $organ->getApprovedOrganInformation();
            if (null != $organInfo && null != $organInfo->getEmail()) {
                $this->emailService->sendEmailAsOrgan(
                    $type,
                    $view,
                    $subject,
                    ['activity' => $activity, 'requester' => $organ->getName()],
                    $organInfo
                );
            } else {
                // The organ did not fill in it's email address, so send the email as the requested user.
                $this->emailService->sendEmailAsUser(
                    $type,
                    $view,
                    $subject,
                    ['activity' => $activity, 'requester' => $organ->getName()],
                    $user
                );
            }
        } else {
            $subject = sprintf('Member Initiative: %s on %s', $activityTitle, $activityTime);

            $this->emailService->sendEmailAsUser(
                $type,
                $view,
                $subject,
                ['activity' => $activity, 'requester' => $user->getMember()->getFullName()],
                $user
            );
        }
    }

    /**
     * Create a new update proposal from user form.
     *
     * @param ActivityModel $currentActivity
     * @param Parameters $data
     *
     * @return bool indicating whether the update was applied or is pending
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createUpdateProposal(ActivityModel $currentActivity, Parameters $data)
    {
        if (!$this->aclService->isAllowed('update', $currentActivity)) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to update this activity'));
        }

        $form = $this->getActivityForm();
        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        // Find the creator
        $user = $this->aclService->getIdentityOrThrowException();

        // Find the organ the activity belongs to, and see if the user has permission to create an activity
        // for this organ. If the id is 0, the activity belongs to no organ.
        $organId = intval($data['organ']);
        $organ = null;

        if (0 !== $organId) {
            $organ = $this->findOrgan($organId);
        }

        // Find the company the activity belongs to. If the id is 0, the activity belongs to no company.
        $companyId = intval($data['company']);
        $company = null;

        if (0 !== $companyId) {
            $company = $this->companyService->getCompanyById($companyId);
        }

        $currentActivityArray = $currentActivity->toArray();
        $proposalActivityArray = $data->toArray();

        $proposalActivityArray['company'] = is_null($company) ? null : $company->getId();
        $proposalActivityArray['organ'] = is_null($organ) ? null : $organ->getId();

        if (!$this->isUpdateProposalNew($currentActivityArray, $proposalActivityArray)) {
            return false;
        }

        $newActivity = $this->saveActivityData(
            $data,
            $user,
            $organ,
            $company,
            ActivityModel::STATUS_UPDATE
        );

        $em = $this->entityManager;

        // TODO: ->count and ->unwrap are undefined
        if (0 !== $currentActivity->getUpdateProposal()->count()) {
            $proposal = $currentActivity->getUpdateProposal()->unwrap()->first();
            //Remove old update proposal
            $oldUpdate = $proposal->getNew();
            $proposal->setNew($newActivity);
            $em->remove($oldUpdate);
        } else {
            $proposal = new ActivityProposalModel();
            $proposal->setOld($currentActivity);
            $proposal->setNew($newActivity);
            $em->persist($proposal);
        }
        $em->flush();

        // Try to directly update the proposal.
        if ($this->canApplyUpdateProposal($currentActivity)) {
            $this->updateActivity($proposal);

            // Send an e-mail stating that the activity was updated.
            $this->emailService->sendEmail(
                'activity_creation',
                'email/activity-updated',
                'Activiteit aangepast op de GEWIS website | Activity was updated on the GEWIS website',
                ['activity' => $newActivity]
            );

            return true;
        }

        // Send an e-mail stating that an activity update proposal has been made.
        $this->emailService->sendEmail(
            'activity_creation',
            'email/activity-update-proposed',
            'Activiteit aanpassingsvoorstel op de GEWIS website | Activity update proposed on the GEWIS website',
            ['activity' => $newActivity, 'proposal' => $proposal]
        );

        return true;
    }

    /**
     * Check if an update proposal is actually an update.
     *
     * @param array $current
     * @param array $proposal
     *
     * @return bool
     */
    protected function isUpdateProposalNew($current, $proposal)
    {
        unset($current['id']);

        // Convert all DateTimes in the original Activity to strings.
        array_walk_recursive($current, function (&$v, $k) {
            if ($v instanceof DateTime) {
                $v = $v->format('Y/m/d H:i');
            }
        });

        // We do not need the ActivityCategory models, hence we replace it with the ids of each one. However, it is no
        // longer a model and requires array access to get the id.
        array_walk($current['categories'], function (&$v, $k) {
            $v = strval($v['id']);
        });

        // HTML forms do not know anything about booleans, hence we need to
        // convert the strings to something we can use.
        array_walk_recursive($proposal, function (&$v, $k) {
            if (in_array($k, ['isMyFuture', 'requireGEFLITST', 'onlyGEWIS', 'displaySubscribedNumber'], true)) {
                $v = boolval($v);
            }
        });

        // Options are a string after submission, not an array of strings. It is easier to explode the values of
        // `$proposal` instead of having to implode `$current` (which requires an extra `array_filter()`).
        if (isset($proposal['signupLists'])) {
            foreach ($proposal['signupLists'] as $keyOuter => $signupList) {
                if (isset($signupList['fields'])) {
                    foreach ($signupList['fields'] as $keyInner => $field) {
                        if (array_key_exists('options', $field)) {
                            $proposal['signupLists'][$keyOuter]['fields'][$keyInner]['options'] = explode(
                                ',',
                                $field['options']
                            );
                        }

                        if (array_key_exists('optionsEn', $field)) {
                            $proposal['signupLists'][$keyOuter]['fields'][$keyInner]['optionsEn'] = explode(
                                ',',
                                $field['optionsEn']
                            );
                        }
                    }
                }
            }
        }

        // Remove some of the form attributes.
        unset($proposal['language_dutch'], $proposal['language_english'], $proposal['submit']);

        // Get the difference between the original Activity and the update
        // proposal. Because we want to detect additions and deletions in
        // the activity data, we actually have to check both ways. After
        // this unset all `id`s after getting the diff to reduce the number
        // of calls.
        $diff1 = $this->array_diff_assoc_recursive($current, $proposal);
        $diff2 = $this->array_diff_assoc_recursive($proposal, $current);
        $this->recursiveUnsetKey($diff1, 'id');
        $this->recursiveUnsetKey($diff2, 'id');

        // Filter out all empty parts of the differences, if both are empty
        // nothing has changed on form submission.
        if (
            empty($this->array_filter_recursive($diff1))
            && empty($this->array_filter_recursive($diff2))
        ) {
            return false;
        }

        return true;
    }

    /**
     * `array_diff_assoc` but recursively. Used to compare an update proposal of an activity
     * to the original activity.
     *
     * Adapted from https://www.php.net/manual/en/function.array-diff-assoc.php#usernotes.
     *
     * @return array
     */
    protected function array_diff_assoc_recursive(array $array1, array $array2)
    {
        $difference = [];

        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!array_key_exists($key, $array2) || !is_array($array2[$key])) {
                    $difference[$key] = $value;
                } else {
                    $newDifference = $this->array_diff_assoc_recursive($value, $array2[$key]);

                    if (!empty($newDifference)) {
                        $difference[$key] = $newDifference;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key] = $value;
            }
        }

        return $difference;
    }

    /**
     * Recursively unset a key in an array (by reference).
     *
     * @param array $array
     * @param string $key
     */
    protected function recursiveUnsetKey(&$array, $key)
    {
        unset($array[$key]);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveUnsetKey($value, $key);
            }
        }
    }

    /**
     * `array_filter` but recursively. Used to compare an update proposal of an activity
     * to the original activity.
     *
     * @return array
     */
    protected function array_filter_recursive(array $array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->array_filter_recursive($value);
            }

            if (in_array($array[$key], ['', null, []], true)) {
                unset($array[$key]);
            }
        }

        return $array;
    }

    /**
     * Checks whether the current user is allowed to apply an update proposal for the given activity.
     *
     * @return bool indicating whether the update may be applied
     */
    protected function canApplyUpdateProposal(ActivityModel $activity)
    {
        if ($this->aclService->isAllowed('update', 'activity')) {
            return true;
        }

        if (!$this->aclService->isAllowed('update', $activity)) {
            return false;
        }

        // If the activity has not been approved the update proposal can be applied.
        return ActivityModel::STATUS_TO_APPROVE === $activity->getStatus();
    }

    /**
     * Apply a proposed activity update.
     */
    public function updateActivity(ActivityProposalModel $proposal)
    {
        $old = $proposal->getOld();
        $new = $proposal->getNew();

        // If the old activity was already approved, keep it approved.
        // Otherwise the status of the new Activity becomes
        // ActivityModel::STATUS_TO_APPROVE.
        if (ActivityModel::STATUS_APPROVED !== $old->getStatus()) {
            $new->setStatus(ActivityModel::STATUS_TO_APPROVE);
        } else {
            $new->setStatus(ActivityModel::STATUS_APPROVED);
        }

        $em = $this->entityManager;

        // The proposal association is no longer needed and can safely be
        // removed. The old Activity is also removed, as we would otherwise have
        // to switch all attributes from the new Activity to the old one (which
        // can only cause problems).
        $em->remove($proposal);
        $em->remove($old);
        $em->flush();
    }

    /**
     * Revoke a proposed activity update
     * NB: This action permanently removes the proposal, so this cannot be undone.
     * (The potentially updated activity remains untouched).
     */
    public function revokeUpdateProposal(ActivityProposalModel $proposal)
    {
        $em = $this->entityManager;
        $new = $proposal->getNew();
        $em->remove($proposal);
        $em->remove($new);
        $em->flush();
    }

    /**
     * Approve of an activity.
     */
    public function approve(ActivityModel $activity)
    {
        if (!$this->aclService->isAllowed('approve', 'activity')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to change the status of the activity')
            );
        }
        $activity->setStatus(ActivityModel::STATUS_APPROVED);
        $activity->setApprover($this->aclService->getIdentity());
        $em = $this->entityManager;
        $em->persist($activity);
        $em->flush();
    }

    /**
     * Reset the approval status of an activity.
     */
    public function reset(ActivityModel $activity)
    {
        if (!$this->aclService->isAllowed('reset', 'activity')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to change the status of the activity')
            );
        }

        $activity->setStatus(ActivityModel::STATUS_TO_APPROVE);
        $activity->setApprover(null);
        $em = $this->entityManager;
        $em->persist($activity);
        $em->flush();
    }

    /**
     * Disapprove of an activity.
     */
    public function disapprove(ActivityModel $activity)
    {
        if (!$this->aclService->isAllowed('disapprove', 'activity')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to change the status of the activity')
            );
        }

        $activity->setStatus(ActivityModel::STATUS_DISAPPROVED);
        $activity->setApprover($this->aclService->getIdentity());
        $em = $this->entityManager;
        $em->persist($activity);
        $em->flush();
    }
}
