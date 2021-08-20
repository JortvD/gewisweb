<?php

namespace Frontpage\Service;

use Application\Service\Email as EmailService;
use DateTime;
use Doctrine\ORM\ORMException;
use DoctrineORMModule\Paginator\Adapter\DoctrinePaginator;
use Laminas\Stdlib\Parameters;
use Frontpage\Form\{
    Poll as PollForm,
    PollApproval as PollApprovalForm,
    PollComment as PollCommentForm,
};
use Frontpage\Mapper\{
    Poll as PollMapper,
};
use Frontpage\Model\{
    Poll as PollModel,
    PollComment as PollCommentModel,
    PollOption as PollOptionModel,
    PollOption,
    PollVote as PollVoteModel};
use Laminas\Mvc\I18n\Translator;
use RuntimeException;
use User\Model\User as UserModel;
use User\Permissions\NotAllowedException;

/**
 * Poll service.
 */
class Poll
{
    /**
     * @var Translator
     */
    private Translator $translator;

    /**
     * @var EmailService
     */
    private EmailService $emailService;

    /**
     * @var PollMapper
     */
    private PollMapper $pollMapper;

    /**
     * @var PollForm
     */
    private PollForm $pollForm;

    /**
     * @var PollCommentForm
     */
    private PollCommentForm $pollCommentForm;

    /**
     * @var PollApprovalForm
     */
    private PollApprovalForm $pollApprovalForm;

    /**
     * @var AclService
     */
    private AclService $aclService;

    public function __construct(
        Translator $translator,
        EmailService $emailService,
        PollMapper $pollMapper,
        PollForm $pollForm,
        PollCommentForm $pollCommentForm,
        PollApprovalForm $pollApprovalForm,
        AclService $aclService
    ) {
        $this->translator = $translator;
        $this->emailService = $emailService;
        $this->pollMapper = $pollMapper;
        $this->pollForm = $pollForm;
        $this->pollCommentForm = $pollCommentForm;
        $this->pollApprovalForm = $pollApprovalForm;
        $this->aclService = $aclService;
    }

    /**
     * Returns the newest approved poll or null if there is none.
     *
     * @return PollModel|null
     */
    public function getNewestPoll(): ?PollModel
    {
        return $this->pollMapper->getNewestPoll();
    }

    /**
     * Retrieves a poll by its id.
     *
     * @param int $pollId the id of the poll to retrieve
     *
     * @return PollModel|null
     *
     * @throws NotAllowedException if the user isn't allowed to see unapproved polls
     */
    public function getPoll(int $pollId): ?PollModel
    {
        $poll = $this->pollMapper->findPollById($pollId);

        if (is_null($poll->getApprover()) && !$this->aclService->isAllowed('view_unapproved', 'poll')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to view unapproved polls')
            );
        }

        return $poll;
    }

    /**
     * Retrieves a poll option by its id.
     *
     * @param int $optionId The id of the poll option to retrieve
     *
     * @return PollOptionModel|null
     *
     * @throws ORMException
     */
    public function getPollOption(int $optionId): ?PollOptionModel
    {
        return $this->pollMapper->findPollOptionById($optionId);
    }

    /**
     * Returns a paginator adapter for paging through polls.
     *
     * @return DoctrinePaginator
     */
    public function getPaginatorAdapter(): DoctrinePaginator
    {
        return $this->pollMapper->getPaginatorAdapter();
    }

    /**
     * Returns all polls which are awaiting approval.
     *
     * @return array
     */
    public function getUnapprovedPolls(): array
    {
        return $this->pollMapper->getUnapprovedPolls();
    }

    /**
     * Returns details about a poll.
     *
     * @param PollModel|null $poll
     *
     * @return array|null
     */
    public function getPollDetails(?PollModel $poll): ?array
    {
        if (is_null($poll)) {
            return null;
        }

        $canVote = $this->canVote($poll);
        $userVote = $this->getVote($poll);

        return [
            'canVote' => $canVote,
            'userVote' => $userVote,
        ];
    }

    /**
     * Determines whether the current user can vote on the given poll.
     *
     * @param PollModel $poll
     *
     * @return bool
     */
    public function canVote(PollModel $poll): bool
    {
        if (!$this->aclService->isAllowed('vote', 'poll')) {
            return false;
        }

        // Check if poll expires after today
        if ($poll->getExpiryDate() <= (new DateTime())) {
            return false;
        }

        // check if poll is approved
        if (is_null($poll->getApprover())) {
            return false;
        }

        return is_null($this->getVote($poll));
    }

    /**
     * Retrieves the current user's vote for a given poll.
     * Returns null if the user hasn't voted on the poll.
     *
     * @param PollModel $poll
     *
     * @return PollVoteModel|null
     */
    public function getVote(PollModel $poll): ?PollVoteModel
    {
        $user = $this->aclService->getIdentity();

        if ($user instanceof UserModel) {
            return $this->pollMapper->findVote($poll->getId(), $user->getLidnr());
        }

        return null;
    }

    /**
     * Stores a vote for the current user.
     *
     * @param PollOptionModel|null $pollOption The option to vote on
     *
     * @return bool indicating whether the vote was submitted
     *
     * @throws ORMException
     */
    public function submitVote(?PollOptionModel $pollOption): bool
    {
        if (is_null($pollOption)) {
            return false;
        }

        $poll = $pollOption->getPoll();
        if (!$this->canVote($poll)) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to vote on this poll.'));
        }

        $pollVote = new PollVoteModel();
        $pollVote->setRespondent($this->aclService->getIdentity());
        $pollVote->setPoll($poll);
        $pollOption->addVote($pollVote);
        $this->pollMapper->persist($pollOption);
        $this->pollMapper->flush();

        return true;
    }

    /**
     * Creates a comment on the given poll.
     *
     * @param int $pollId
     * @param Parameters $data
     *
     * @return bool
     *
     * @throws ORMException
     */
    public function createComment(int $pollId, Parameters $data): bool
    {
        if (!$this->aclService->isAllowed('create', 'poll_comment')) {
            throw new NotAllowedException(
                $this->translator->translate('You are not allowed to create comments on this poll')
            );
        }

        $form = $this->pollCommentForm;
        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $user = $this->aclService->getIdentity();
        $comment = $this->saveCommentData($form->getData(), $user);

        $poll = $this->getPoll($pollId);
        $poll->addComment($comment);

        $this->pollMapper->persist($poll);
        $this->pollMapper->flush();

        // reset the form
        $form->setData(['author' => '', 'content' => '']);

        return true;
    }

    /**
     * Save data for a poll comment.
     *
     * @param array $data
     * @param UserModel $user
     *
     * @return PollCommentModel
     *
     * @throws ORMException
     */
    public function saveCommentData(array $data, UserModel $user): PollCommentModel
    {
        $comment = new PollCommentModel();
        $comment->setAuthor($data['author']);
        $comment->setContent($data['content']);
        $comment->setCreatedOn(new DateTime());
        $comment->setUser($user);

        $this->pollMapper->persist($comment);

        return $comment;
    }

    /**
     * Saves a new poll request.
     *
     * @param Parameters $data
     *
     * @return bool indicating whether the request succeeded
     *
     * @throws ORMException
     */
    public function requestPoll(Parameters $data): bool
    {
        $form = $this->getPollForm();
        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        // TODO: Change to {@link getIdentityOrThrowException()}.
        $user = $this->aclService->getIdentity();
        $poll = $this->savePollData($form->getData(), $user);

        $this->pollMapper->persist($poll);
        $this->pollMapper->flush();

        $this->emailService->sendEmail(
            'poll_creation',
            'email/poll',
            'Er is een nieuwe poll aangevraagd | A new poll has been requested',
            ['poll' => $poll]
        );

        return true;
    }

    /**
     * @param array $data
     * @param UserModel $user
     *
     * @return PollModel
     *
     * @throws ORMException
     */
    public function savePollData(array $data, UserModel $user): PollModel
    {
        $poll = new PollModel();
        $poll->setDutchQuestion($data['dutchQuestion']);
        $poll->setEnglishQuestion($data['englishQuestion']);

        foreach ($data['options'] as $option) {
            $pollOption = $this->createPollOption($option, $poll);
            $this->pollMapper->persist($pollOption);
        }

        $poll->setExpiryDate(new DateTime());
        $poll->setCreator($user);

        return $poll;
    }

    /**
     * @param array $data
     * @param PollModel $poll
     *
     * @return PollOptionModel
     */
    public function createPollOption(array $data, PollModel $poll): PollOption
    {
        $pollOption = new PollOptionModel();
        $pollOption->setDutchText($data['dutchText']);
        $pollOption->setEnglishText($data['englishText']);
        $pollOption->setPoll($poll);

        return $pollOption;
    }

    /**
     * Returns the poll request/creation form.
     *
     * @return PollForm
     */
    public function getPollForm(): PollForm
    {
        if (!$this->aclService->isAllowed('request', 'poll')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to request polls'));
        }

        return $this->pollForm;
    }

    /**
     * Deletes the given poll.
     *
     * @param PollModel $poll The poll to delete
     *
     * @throws ORMException
     */
    public function deletePoll(PollModel $poll): void
    {
        if (!$this->aclService->isAllowed('delete', 'poll')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to delete polls'));
        }

        // Check to see if poll is approved
        if ($poll->isApproved()) {
            // Instead of removing, set expiry date to 'now' to hide poll.
            $poll->setExpiryDate(new DateTime());
        } else {
            // If not approved, just remove the junk from the database.
            $this->pollMapper->remove($poll);
        }

        $this->pollMapper->flush();
    }

    /**
     * Approves the given poll.
     *
     * @param PollModel $poll The poll to approve
     * @param Parameters $data The data from the poll approval form
     *
     * @return bool indicating whether the approval succeeded
     *
     * @throws ORMException
     */
    public function approvePoll(PollModel $poll, Parameters $data): bool
    {
        $approvalForm = $this->getPollApprovalForm();
        $approvalForm->bind($poll);
        $approvalForm->setData($data);

        if (!$approvalForm->isValid()) {
            return false;
        }

        $poll->setApprover($this->aclService->getIdentity());
        $this->pollMapper->flush();
        return true;
    }

    /**
     * Returns the poll approval form.
     *
     * @return PollApprovalForm
     */
    public function getPollApprovalForm(): PollApprovalForm
    {
        if (!$this->aclService->isAllowed('approve', 'poll')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to approve polls'));
        }

        return $this->pollApprovalForm;
    }
}
