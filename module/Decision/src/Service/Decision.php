<?php

namespace Decision\Service;

use Application\Service\AbstractAclService;
use Application\Service\Email;
use Application\Service\FileStorage;
use Decision\Form\Authorization;
use Decision\Form\Document;
use Decision\Form\Notes;
use Decision\Form\ReorderDocument;
use Decision\Form\SearchDecision;
use Decision\Model\Authorization as AuthorizationModel;
use Decision\Model\Meeting;
use Decision\Model\MeetingDocument;
use Decision\Model\MeetingNotes as NotesModel;
use Doctrine\ORM\PersistentCollection;
use InvalidArgumentException;
use Laminas\Mvc\I18n\Translator;
use Laminas\Permissions\Acl\Acl;
use User\Permissions\NotAllowedException;
use User\Service\User;

/**
 * Decision service.
 */
class Decision extends AbstractAclService
{
    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var \User\Model\User|string
     */
    private $userRole;

    /**
     * @var Acl
     */
    private $acl;

    /**
     * @var User
     */
    private $userService;

    /**
     * @var FileStorage
     */
    private $storageService;

    /**
     * @var Email
     */
    private $emailService;

    /**
     * @var \Decision\Mapper\Member
     */
    private $memberMapper;

    /**
     * @var \Decision\Mapper\Meeting
     */
    private $meetingMapper;

    /**
     * @var \Decision\Mapper\Decision
     */
    private $decisionMapper;

    /**
     * @var \Decision\Mapper\Authorization
     */
    private $authorizationMapper;

    /**
     * @var Notes
     */
    private $notesForm;

    /**
     * @var Document
     */
    private $documentForm;

    /**
     * @var ReorderDocument
     */
    private $reorderDocumentForm;

    /**
     * @var SearchDecision
     */
    private $searchDecisionForm;

    /**
     * @var Authorization
     */
    private $authorizationForm;

    public function __construct(
        Translator $translator,
        $userRole,
        Acl $acl,
        User $userService,
        FileStorage $storageService,
        Email $emailService,
        \Decision\Mapper\Member $memberMapper,
        \Decision\Mapper\Meeting $meetingMapper,
        \Decision\Mapper\Decision $decisionMapper,
        \Decision\Mapper\Authorization $authorizationMapper,
        Notes $notesForm,
        Document $documentForm,
        ReorderDocument $reorderDocumentForm,
        SearchDecision $searchDecisionForm,
        Authorization $authorizationForm
    )
    {
        $this->translator = $translator;
        $this->userRole = $userRole;
        $this->acl = $acl;
        $this->userService = $userService;
        $this->storageService = $storageService;
        $this->emailService = $emailService;
        $this->memberMapper = $memberMapper;
        $this->meetingMapper = $meetingMapper;
        $this->decisionMapper = $decisionMapper;
        $this->authorizationMapper = $authorizationMapper;
        $this->notesForm = $notesForm;
        $this->documentForm = $documentForm;
        $this->reorderDocumentForm = $reorderDocumentForm;
        $this->searchDecisionForm = $searchDecisionForm;
        $this->authorizationForm = $authorizationForm;
    }

    public function getRole()
    {
        return $this->userRole;
    }

    /**
     * Get the translator.
     *
     * @return Translator
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * Get all meetings.
     *
     * @param int|null $limit The amount of meetings to retrieve, default is all
     *
     * @return array Of all meetings
     */
    public function getMeetings($limit = null)
    {
        if (!$this->isAllowed('list_meetings')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to list meetings.'));
        }

        return $this->meetingMapper->findAll($limit);
    }

    /**
     * Get past meetings.
     *
     * @param int|null $limit The amount of meetings to retrieve, default is all
     * @param string|null $type Constraint on the type of the meeting, default is none
     *
     * @return array Of all meetings
     */
    public function getPastMeetings($limit = null, $type = null)
    {
        if (!$this->isAllowed('list_meetings')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to list meetings.'));
        }

        return $this->meetingMapper->findPast($limit, $type);
    }

    public function getMeetingsByType($type)
    {
        if (!$this->isAllowed('list_meetings')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to list meetings.'));
        }

        return $this->meetingMapper->findByType($type);
    }

    /**
     * Get information about one meeting.
     *
     * @param string $type
     * @param int $number
     *
     * @return Meeting
     */
    public function getMeeting($type, $number)
    {
        if (!$this->isAllowed('view', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to view meetings.'));
        }

        return $this->meetingMapper->find($type, $number);
    }

    /**
     * Returns the latest upcoming AV or null if there is none.
     *
     * @return Meeting|null
     */
    public function getLatestAV()
    {
        return $this->meetingMapper->findLatestAV();
    }

    /**
     * Returns the closest upcoming meeting for members.
     *
     * @return Meeting|null
     */
    public function getUpcomingMeeting()
    {
        return $this->meetingMapper->findUpcomingMeeting();
    }

    /**
     * Get meeting documents corresponding to a certain id.
     *
     * @param $id
     *
     * @return MeetingDocument
     */
    public function getMeetingDocument($id)
    {
        return $this->meetingMapper->findDocument($id);
    }

    /**
     * Returns a download for a meeting document.
     *
     * @param MeetingDocument $meetingDocument
     *
     * @return response|null
     */
    public function getMeetingDocumentDownload($meetingDocument)
    {
        if (!$this->isAllowed('view_documents', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to view meeting documents.'));
        }

        if (is_null($meetingDocument)) {
            return null;
        }

        $path = $meetingDocument->getPath();
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $fileName = $meetingDocument->getName() . '.' . $extension;

        return $this->storageService->downloadFile($path, $fileName);
    }

    /**
     * Returns a download for meeting notes.
     *
     * @return response|null
     */
    public function getMeetingNotesDownload(Meeting $meeting)
    {
        if (!$this->isAllowed('view_notes', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to view meeting notes.'));
        }

        if (is_null($meeting->getNotes())) {
            return null;
        }

        $path = $meeting->getNotes()->getPath();
        $fileName = $meeting->getType() . '-' . $meeting->getNumber() . '.pdf';

        return $this->storageService->downloadFile($path, $fileName);
    }

    /**
     * Upload meeting notes.
     *
     * @param array|Traversable $post
     * @param array|Traversable $files
     *
     * @return bool If uploading was a success
     */
    public function uploadNotes($post, $files)
    {
        $form = $this->getNotesForm();

        $data = array_merge_recursive($post->toArray(), $files->toArray());

        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $data = $form->getData();
        $parts = explode('/', $data['meeting']);
        $meeting = $this->getMeeting($parts[0], $parts[1]);
        $path = $this->storageService->storeUploadedFile($data['upload']);

        $meetingNotes = $meeting->getNotes();
        if (is_null($meetingNotes)) {
            $meetingNotes = new NotesModel();
            $meetingNotes->setMeeting($meeting);
        }
        $meetingNotes->setPath($path);

        $mapper = $this->decisionMapper;
        $mapper->persist($meetingNotes);

        return true;
    }

    /**
     * Upload a meeting document.
     *
     * @param array|Traversable $post
     * @param array|Traversable $files
     *
     * @return bool If uploading was a success
     */
    public function uploadDocument($post, $files)
    {
        $form = $this->getDocumentForm();

        $data = array_merge_recursive($post->toArray(), $files->toArray());

        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }

        $data = $form->getData();

        $path = $this->storageService->storeUploadedFile($data['upload']);

        $meeting = explode('/', $data['meeting']);
        $meeting = $this->getMeeting($meeting[0], $meeting[1]);

        $document = new MeetingDocument();
        $document->setPath($path);
        $document->setName($data['name']);
        $document->setMeeting($meeting);

        // Determine document's position in ordering
        $maxPosition = $this->meetingMapper->findMaxDocumentPosition($meeting);
        $position = is_null($maxPosition) ? 0 : ++$maxPosition; // NULL if meeting doesn't have documents yet

        $document->setDisplayPosition($position);

        $this->meetingMapper->persistDocument($document);

        return true;
    }

    public function deleteDocument($post)
    {
        if (!$this->isAllowed('delete_document', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to delete meeting documents.'));
        }
        $id = $post->toArray()['document'];
        $document = $this->getMeetingDocument($id);
        $this->meetingMapper->remove($document);
    }

    /**
     * Changes a document's position in the ordering.
     *
     * The basic flow is (1) retrieve documents, (2) swap document positions,
     * then (3) persist position. Unfortunately, I have to update the positions
     * of all documents related to a meeting because of legacy. Old documents
     * don't have a position yet, so they are set to 0 by default.
     *
     * FUTURE: When documents have display positions, simplify the code by only
     * mutating two rows.
     *
     * @param int $id Document ID
     * @param bool $moveDown If the document should be moved down in the ordering, defaults to TRUE
     *
     * @return void
     *
     * @throws NotAllowedException
     * @throws InvalidArgumentException If the document doesn't exist
     */
    public function changePositionDocument($id, $moveDown = true)
    {
        $errorMessage = 'You are not allowed to modify meeting documents.';

        $this->isAllowedOrFail('upload_document', 'meeting', $errorMessage);

        // Documents are ordered because of @OrderBy annotation on the relation
        /** @var PersistentCollection $documents */
        $documents = $this->meetingMapper
            ->findDocumentOrFail($id)
            ->getMeeting()
            ->getDocuments();

        // Create data structure to derive ordering, key is position and value
        // is document ID
        $ordering = $documents->map(function (MeetingDocument $document) {
            return $document->getId();
        });

        $oldPosition = $ordering->indexOf($id);
        $newPosition = (true === $moveDown) ? ($oldPosition + 1) : ($oldPosition - 1);

        // Do nothing if the document is already at the top/bottom
        if ($newPosition < 0 || $newPosition > ($ordering->count() - 1)) {
            return;
        }

        // Swap positions
        $ordering->set($oldPosition, $ordering->get($newPosition));
        $ordering->set($newPosition, $id);

        // Persist new positions
        $documents->map(function (MeetingDocument $document) use ($ordering) {
            $position = $ordering->indexOf($document->getId());

            $document->setDisplayPosition($position);

            $this->meetingMapper->persistDocument($document);
        });
    }

    /**
     * Search for decisions.
     *
     * @param array|Traversable $data Search data
     *
     * @return array Search results
     */
    public function search($data)
    {
        if (!$this->isAllowed('search')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to search decisions.'));
        }

        $form = $this->getSearchDecisionForm();

        $form->setData($data);

        if (!$form->isValid()) {
            return null;
        }

        $data = $form->getData();

        return $this->decisionMapper->search($data['query']);
    }

    /**
     * Retrieves all authorizations for the given meeting number.
     *
     * @param int $meetingNumber
     *
     * @return array
     */
    public function getAllAuthorizations($meetingNumber)
    {
        if (!$this->isAllowed('view_all', 'authorization')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to view all authorizations.'));
        }

        return $this->authorizationMapper->find($meetingNumber);
    }

    /**
     * Gets the authorization of the current user for the given meeting.
     *
     * @param int $meetingNumber
     *
     * @return AuthorizationModel|null
     */
    public function getUserAuthorization($meetingNumber)
    {
        if (!$this->isAllowed('view_own', 'authorization')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to view authorizations.'));
        }

        $lidnr = $this->userService->getIdentity()->getLidnr();

        return $this->authorizationMapper->findUserAuthorization($meetingNumber, $lidnr);
    }

    public function createAuthorization($data)
    {
        $form = $this->getAuthorizationForm();
        $authorization = new AuthorizationModel();
        $form->setData($data);

        if (!$form->isValid()) {
            return false;
        }
        $user = $this->userService->getIdentity();
        $authorizer = $user->getMember();
        $recipient = $this->memberMapper->findByLidnr($data['recipient']);
        if (is_null($recipient) || $recipient->getLidnr() === $authorizer->getLidnr()) {
            return false;
        }

        $meeting = $this->getLatestAV();
        if (is_null($meeting)) {
            return false;
        }

        $authorization->setAuthorizer($authorizer);
        $authorization->setRecipient($recipient);
        $authorization->setMeetingNumber($meeting->getNumber());
        $this->authorizationMapper->persist($authorization);

        // Send an email to the recipient
        $this->emailService->sendEmailAsUserToUser(
            $recipient,
            'email/authorization_received',
            'Machtiging ontvangen | Authorization received',
            ['authorization' => $authorization],
            $authorizer
        );

        // Send a confirmation email to the authorizing member
        $this->emailService->sendEmailAsUserToUser(
            $authorizer,
            'email/authorization_sent',
            'Machtiging verstuurd | Authorization sent',
            ['authorization' => $authorization],
            $recipient
        );

        return $authorization;
    }

    /**
     * Get the Notes form.
     *
     * @return Notes
     */
    public function getNotesForm()
    {
        if (!$this->isAllowed('upload_notes', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to upload notes.'));
        }

        return $this->notesForm;
    }

    /**
     * Get the Document form.
     *
     * @return Document
     */
    public function getDocumentForm()
    {
        if (!$this->isAllowed('upload_document', 'meeting')) {
            throw new NotAllowedException($this->translator->translate('You are not allowed to upload meeting documents.'));
        }

        return $this->documentForm;
    }

    public function getReorderDocumentForm()
    {
        $errorMessage = 'You are not allowed to modify meeting documents.';

        $this->isAllowedOrFail('upload_document', 'meeting', $errorMessage);

        return $this->reorderDocumentForm;
    }

    /**
     * Get the SearchDecision form.
     *
     * @return SearchDecision
     */
    public function getSearchDecisionForm()
    {
        return $this->searchDecisionForm;
    }

    /**
     * Get the Authorization form.
     *
     * @return Authorization
     */
    public function getAuthorizationForm()
    {
        if (!$this->isAllowed('create', 'authorization')) {
            throw new NotAllowedException($this->translator->translate('You are not authorize people.'));
        }

        return $this->authorizationForm;
    }

    /**
     * Get the default resource ID.
     *
     * @return string
     */
    protected function getDefaultResourceId()
    {
        return 'decision';
    }

    /**
     * Get the Acl.
     *
     * @return Acl
     */
    public function getAcl()
    {
        return $this->acl;
    }

    /**
     * Returns whether the current role is allowed to view files.
     *
     * @return bool
     */
    public function isAllowedToBrowseFiles()
    {
        return $this->isAllowed('browse', 'files');
    }

    /**
     * Checks the user's permission.
     *
     * @param string $operation
     * @param string $resource
     * @param string $errorMessage English error message
     *
     * @throws NotAllowedException If the user doesn't have permission
     */
    private function isAllowedOrFail($operation, $resource, $errorMessage)
    {
        if (!$this->isAllowed($operation, $resource)) {
            throw new NotAllowedException($this->translator->translate($errorMessage));
        }
    }
}