<?php

/**
 * @file classes/submission/form/PKPSubmissionSubmitStep3Form.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPSubmissionSubmitStep3Form
 * @ingroup submission_form
 *
 * @brief Form for Step 3 of author submission: submission metadata
 */

namespace PKP\submission\form;

use APP\core\Application;

use APP\template\TemplateManager;
use PKP\db\DAORegistry;

class PKPSubmissionSubmitStep3Form extends SubmissionSubmitForm
{
    /** @var SubmissionMetadataFormImplementation */
    public $_metadataFormImplem;

    /**
     * Constructor.
     *
     * @param $context Context
     * @param $submission Submission
     * @param $metadataFormImplementation MetadataFormImplementation
     */
    public function __construct($context, $submission, $metadataFormImplementation)
    {
        parent::__construct($context, $submission, 3);

        $this->setDefaultFormLocale($submission->getLocale());
        $this->_metadataFormImplem = $metadataFormImplementation;
        $this->_metadataFormImplem->addChecks($submission);
    }

    /**
     * @copydoc SubmissionSubmitForm::initData
     */
    public function initData()
    {
        $this->_metadataFormImplem->initData($this->submission);
        return parent::initData();
    }

    /**
     * @copydoc SubmissionSubmitForm::fetch
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();

        // Tell the form what fields are enabled (and which of those are required)
        $metadataFields = Application::getMetadataFields();
        foreach ($metadataFields as $field) {
            $templateMgr->assign([
                $field . 'Enabled' => $context->getData($field) === METADATA_REQUEST || $context->getData($field) === METADATA_REQUIRE,
                $field . 'Required' => $context->getData($field) === METADATA_REQUIRE,
            ]);
        }

        $templateMgr->assign('publicationId', $this->submission->getCurrentPublication()->getId());

        return parent::fetch($request, $template, $display);
    }

    /**
     * Assign form data to user-submitted data.
     */
    public function readInputData()
    {
        $this->_metadataFormImplem->readInputData();
    }

    /**
     * Get the names of fields for which data should be localized
     *
     * @return array
     */
    public function getLocaleFieldNames()
    {
        return $this->_metadataFormImplem->getLocaleFieldNames();
    }

    /**
     * Save changes to submission.
     *
     * @return int the submission ID
     */
    public function execute(...$functionArgs)
    {
        // Execute submission metadata related operations.
        $this->_metadataFormImplem->execute($this->submission, Application::get()->getRequest());

        // Get an updated version of the submission.
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $this->submission = $submissionDao->getById($this->submissionId);

        // Set other submission data.
        if ($this->submission->getSubmissionProgress() <= $this->step) {
            $this->submission->setSubmissionProgress($this->step + 1);
            $this->submission->stampLastActivity();
            $this->submission->stampModified();
        }

        parent::execute(...$functionArgs);

        // Save the submission.
        $submissionDao->updateObject($this->submission);

        return $this->submissionId;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\submission\form\PKPSubmissionSubmitStep3Form', '\PKPSubmissionSubmitStep3Form');
}
