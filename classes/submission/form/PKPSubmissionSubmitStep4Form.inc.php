<?php

/**
 * @file classes/submission/form/PKPSubmissionSubmitStep4Form.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPSubmissionSubmitStep4Form
 * @ingroup submission_form
 *
 * @brief Form for Step 4 of author submission: confirm & complete
 */

namespace PKP\submission\form;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\workflow\EditorDecisionActionsManager;
use PKP\core\Core;

use PKP\db\DAORegistry;
use PKP\notification\PKPNotification;
use PKP\security\Role;

class PKPSubmissionSubmitStep4Form extends SubmissionSubmitForm
{
    /**
     * Constructor.
     *
     * @param $context Context
     * @param $submission Submission
     */
    public function __construct($context, $submission)
    {
        parent::__construct($context, $submission, 4);
    }

    /**
     * Save changes to submission.
     *
     * @return int the submission ID
     */
    public function execute(...$functionArgs)
    {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $request = Application::get()->getRequest();

        // Set other submission data.
        if ($this->submission->getSubmissionProgress() <= $this->step) {
            $this->submission->setDateSubmitted(Core::getCurrentDate());
            $this->submission->stampLastActivity();
            $this->submission->stampModified();
            $this->submission->setSubmissionProgress(0);
        }

        parent::execute(...$functionArgs);

        // Save the submission.
        $submissionDao->updateObject($this->submission);

        // Assign the default stage participants.
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /** @var UserGroupDAO $userGroupDao */
        $notifyUsers = [];

        // Manager and assistant roles -- for each assigned to this
        //  stage in setup, iff there is only one user for the group,
        //  automatically assign the user to the stage.
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
        $submissionStageGroups = $userGroupDao->getUserGroupsByStage($this->submission->getContextId(), WORKFLOW_STAGE_ID_SUBMISSION);
        while ($userGroup = $submissionStageGroups->next()) {
            // Only handle manager and assistant roles
            if (!in_array($userGroup->getRoleId(), [Role::ROLE_ID_MANAGER, Role::ROLE_ID_ASSISTANT])) {
                continue;
            }

            $users = $userGroupDao->getUsersById($userGroup->getId(), $this->submission->getContextId());
            if ($users->getCount() == 1) {
                $user = $users->next();
                $stageAssignmentDao->build($this->submission->getId(), $userGroup->getId(), $user->getId(), $userGroup->getRecommendOnly());
                $notifyUsers[] = $user->getId();
            }
        }

        // Author roles
        // Assign only the submitter in whatever ROLE_ID_AUTHOR capacity they were assigned previously
        $user = $request->getUser();
        $submitterAssignments = $stageAssignmentDao->getBySubmissionAndStageId($this->submission->getId(), null, null, $user->getId());
        while ($assignment = $submitterAssignments->next()) {
            $userGroup = $userGroupDao->getById($assignment->getUserGroupId());
            if ($userGroup->getRoleId() == Role::ROLE_ID_AUTHOR) {
                $stageAssignmentDao->build($this->submission->getId(), $userGroup->getId(), $assignment->getUserId());
                // Only assign them once, since otherwise we'll one assignment for each previous stage.
                // And as long as they are assigned once, they will get access to their submission.
                break;
            }
        }

        $notificationManager = new NotificationManager();

        // Assign sub editors for sections
        $subEditorsDao = DAORegistry::getDAO('SubEditorsDAO'); /** @var SubEditorsDAO $subEditorsDao */
        $subEditors = $subEditorsDao->getBySubmissionGroupId($this->submission->getSectionId(), ASSOC_TYPE_SECTION, $this->submission->getContextId());
        foreach ($subEditors as $subEditor) {
            $userGroups = $userGroupDao->getByUserId($subEditor->getId(), $this->submission->getContextId());
            while ($userGroup = $userGroups->next()) {
                if ($userGroup->getRoleId() != Role::ROLE_ID_SUB_EDITOR) {
                    continue;
                }
                $stageAssignmentDao->build($this->submission->getId(), $userGroup->getId(), $subEditor->getId(), $userGroup->getRecommendOnly());
                // If we assign a stage assignment in the Submission stage to a sub editor, make note.
                if ($userGroupDao->userGroupAssignedToStage($userGroup->getId(), WORKFLOW_STAGE_ID_SUBMISSION)) {
                    $notifyUsers[] = $subEditor->getId();
                }
            }
        }

        // Assign sub editors for categories
        $categoryDao = DAORegistry::getDAO('CategoryDAO'); /** @var CategoryDAO $categoryDao */
        $subEditorsDao = DAORegistry::getDAO('SubEditorsDAO'); /** @var SubEditorsDAO $subEditorsDao */
        $categories = $categoryDao->getByPublicationId($this->submission->getCurrentPublication()->getId());
        while ($category = $categories->next()) {
            $subEditors = $subEditorsDao->getBySubmissionGroupId($category->getId(), ASSOC_TYPE_CATEGORY, $this->submission->getContextId());
            foreach ($subEditors as $subEditor) {
                $userGroups = $userGroupDao->getByUserId($subEditor->getId(), $this->submission->getContextId());
                while ($userGroup = $userGroups->next()) {
                    if ($userGroup->getRoleId() != Role::ROLE_ID_SUB_EDITOR) {
                        continue;
                    }
                    $stageAssignmentDao->build($this->submission->getId(), $userGroup->getId(), $subEditor->getId(), $userGroup->getRecommendOnly());
                    // If we assign a stage assignment in the Submission stage to a sub editor, make note.
                    if ($userGroupDao->userGroupAssignedToStage($userGroup->getId(), WORKFLOW_STAGE_ID_SUBMISSION)) {
                        $notifyUsers[] = $subEditor->getId();
                    }
                }
            }
        }

        // Update assignment notifications
        $notificationManager->updateNotification(
            $request,
            (new EditorDecisionActionsManager())->getStageNotifications(),
            null,
            ASSOC_TYPE_SUBMISSION,
            $this->submission->getId()
        );

        // Send a notification to associated users if an editor needs assigning
        if (empty($notifyUsers)) {
            $roleDao = DAORegistry::getDAO('RoleDAO'); /** @var RoleDAO $roleDao */

            // Get the managers.
            $managers = $roleDao->getUsersByRoleId(Role::ROLE_ID_MANAGER, $this->submission->getContextId());

            $managersArray = $managers->toAssociativeArray();

            $allUserIds = array_keys($managersArray);
            foreach ($allUserIds as $userId) {

                // Add TASK notification indicating that a submission is unassigned
                $notificationManager->createNotification(
                    $request,
                    $userId,
                    PKPNotification::NOTIFICATION_TYPE_EDITOR_ASSIGNMENT_REQUIRED,
                    $this->submission->getContextId(),
                    ASSOC_TYPE_SUBMISSION,
                    $this->submission->getId(),
                    Notification::NOTIFICATION_LEVEL_TASK
                );
            }
        } else {
            foreach ($notifyUsers as $userId) {
                $notificationManager->createNotification(
                    $request,
                    $userId,
                    PKPNotification::NOTIFICATION_TYPE_SUBMISSION_SUBMITTED,
                    $this->submission->getContextId(),
                    ASSOC_TYPE_SUBMISSION,
                    $this->submission->getId()
                );
            }
        }

        $notificationManager->updateNotification(
            $request,
            [PKPNotification::NOTIFICATION_TYPE_APPROVE_SUBMISSION],
            null,
            ASSOC_TYPE_SUBMISSION,
            $this->submission->getId()
        );

        return $this->submissionId;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\submission\form\PKPSubmissionSubmitStep4Form', '\PKPSubmissionSubmitStep4Form');
}
