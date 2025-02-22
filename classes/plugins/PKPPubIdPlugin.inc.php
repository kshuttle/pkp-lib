<?php

/**
 * @file classes/plugins/PKPPubIdPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPPubIdPlugin
 * @ingroup plugins
 *
 * @brief Abstract class for public identifiers plugins
 */

namespace PKP\plugins;

use APP\core\Application;
use APP\notification\NotificationManager;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\db\SchemaDAO;
use PKP\linkAction\LinkAction;

use PKP\linkAction\request\AjaxModal;
use PKP\notification\PKPNotification;

abstract class PKPPubIdPlugin extends LazyLoadPlugin
{
    //
    // Implement template methods from Plugin
    //
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled($mainContextId)) {
            // Enable storage of additional fields.
            foreach ($this->getDAOs() as $dao) {
                // Augment the object with the additional properties required by the pub ID plugin.
                if ($dao instanceof SchemaDAO) {
                    // Schema-backed DAOs need the schema extended.
                    HookRegistry::register('Schema::get::' . $dao->schemaName, [$this, 'addToSchema']);
                } else {
                    // For non-schema-backed DAOs, DAOName::getAdditionalFieldNames can be used.
                    $classNameParts = explode('\\', get_class($dao)); // Separate namespace info from class name
                    HookRegistry::register(strtolower_codesafe(end($classNameParts)) . '::getAdditionalFieldNames', [$this, 'getAdditionalFieldNames']);
                }
            }
        }
        $this->addLocaleData();
        return true;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, $actionArgs),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $user = $request->getUser();
        $router = $request->getRouter();
        $context = $router->getContext($request);

        $form = $this->instantiateSettingsForm($context->getId());
        $notificationManager = new NotificationManager();
        switch ($request->getUserVar('verb')) {
            case 'save':
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager->createTrivialNotification($user->getId(), PKPNotification::NOTIFICATION_TYPE_SUCCESS);
                    return new JSONMessage(true);
                }
                return new JSONMessage(true, $form->fetch($request));
            case 'clearPubIds':
                if (!$request->checkCSRF()) {
                    return new JSONMessage(false);
                }
                $contextDao = Application::getContextDAO();
                $contextDao->deleteAllPubIds($context->getId(), $this->getPubIdType());
                return new JSONMessage(true);
            default:
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }


    //
    // Protected template methods to be implemented by sub-classes.
    //
    /**
     * Get the public identifier.
     *
     * @param $pubObject object
     * 	Submission, Representation, SubmissionFile + OJS Issue
     *
     * @return string
     */
    abstract public function getPubId($pubObject);

    /**
     * Construct the public identifier from its prefix and suffix.
     *
     * @param $pubIdPrefix string
     * @param $pubIdSuffix string
     * @param $contextId integer
     *
     * @return string
     */
    abstract public function constructPubId($pubIdPrefix, $pubIdSuffix, $contextId);

    /**
     * Public identifier type, see
     * http://dtd.nlm.nih.gov/publishing/tag-library/n-4zh0.html
     *
     * @return string
     */
    abstract public function getPubIdType();

    /**
     * Public identifier type that will be displayed to the reader.
     *
     * @return string
     */
    abstract public function getPubIdDisplayType();

    /**
     * Full name of the public identifier.
     *
     * @return string
     */
    abstract public function getPubIdFullName();

    /**
     * Get the whole resolving URL.
     *
     * @param $contextId integer
     * @param $pubId string
     *
     * @return string resolving URL
     */
    abstract public function getResolvingURL($contextId, $pubId);

    /**
     * Get the file (path + filename)
     * to be included into the object's
     * identifiers tab, e.g. for suffix editing.
     *
     * @return string
     */
    abstract public function getPubIdMetadataFile();

    /**
     * Add JavaScript files to be loaded in the metadata file.
     *
     * @param $request PKPRequest
     * @param $templateMgr PKPTemplateManager
     */
    public function addJavaScript($request, $templateMgr)
    {
    }

    /**
     * Get the file (path + filename)
     * for the pub id assignment
     * to be included into other pages.
     *
     * @return string
     */
    abstract public function getPubIdAssignFile();

    /**
     * Get the settings form.
     *
     * @param $contextId integer
     *
     * @return object Settings form
     */
    abstract public function instantiateSettingsForm($contextId);

    /**
     * Get the additional form field names,
     * for metadata, e.g. suffix field name.
     *
     * @return array
     */
    abstract public function getFormFieldNames();

    /**
     * Get the assign option form field name.
     *
     * @return string
     */
    abstract public function getAssignFormFieldName();

    /**
     * Get the the prefix form field name.
     *
     * @return string
     */
    abstract public function getPrefixFieldName();

    /**
     * Get the the suffix form field name.
     *
     * @return string
     */
    abstract public function getSuffixFieldName();

    /**
     * Get the link actions used in the pub id forms,
     * e.g. clear pub id.
     *
     * @return array
     */
    abstract public function getLinkActions($pubObject);

    /**
     * Get the suffix patterns form field names for all objects.
     *
     * @return array (pub object type => suffix pattern field name)
     */
    abstract public function getSuffixPatternsFieldNames();

    /**
     * Get additional field names to be considered for storage.
     *
     * @return array
     */
    abstract public function getDAOFieldNames();

    /**
     * Get the possible publication object types.
     *
     * @return array
     */
    public function getPubObjectTypes()
    {
        return [
            'Publication' => '\APP\publication\Publication',
            'Representation' => '\PKP\submission\Representation',
            'SubmissionFile' => '\PKP\submission\SubmissionFile',
        ];
    }

    /**
     * Is this object type enabled in plugin settings
     *
     * @param $pubObjectType object
     * @param $contextId integer
     *
     * @return boolean
     */
    abstract public function isObjectTypeEnabled($pubObjectType, $contextId);

    /**
     * Get the error message for not unique pub id
     *
     * @return string
     */
    abstract public function getNotUniqueErrorMsg();

    /**
     * Verify form data.
     *
     * @param $fieldName string The form field to be checked.
     * @param $fieldValue string The value of the form field.
     * @param $pubObject object
     * @param $contextId integer
     * @param $errorMsg string Return validation error messages here.
     *
     * @return boolean
     */
    public function verifyData($fieldName, $fieldValue, $pubObject, $contextId, &$errorMsg)
    {
        // Verify pub id uniqueness.
        if ($fieldName == $this->getSuffixFieldName()) {
            if (empty($fieldValue)) {
                return true;
            }

            // Construct the potential new pub id with the posted suffix.
            $pubIdPrefix = $this->getSetting($contextId, $this->getPrefixFieldName());
            if (empty($pubIdPrefix)) {
                return true;
            }
            $newPubId = $this->constructPubId($pubIdPrefix, $fieldValue, $contextId);

            if (!$this->checkDuplicate($newPubId, $pubObject->getId(), get_class($pubObject), $contextId)) {
                $errorMsg = $this->getNotUniqueErrorMsg();
                return false;
            }
        }
        return true;
    }

    /**
     * Check whether the given pubId is valid.
     *
     * @param $pubId string
     *
     * @return boolean
     */
    public function validatePubId($pubId)
    {
        return true; // Assume a valid ID by default;
    }

    /**
     * Return an array of publication object types and
     * the corresponding DAOs.
     *
     * @return array
     */
    public function getDAOs()
    {
        return  [
            DAORegistry::getDAO('PublicationDAO'),
            DAORegistry::getDAO('SubmissionDAO'),
            Application::getRepresentationDAO(),
            DAORegistry::getDAO('SubmissionFileDAO'),
        ];
    }

    /**
     * Can a pub id be assigned to the object.
     *
     * @param $pubObject object
     * 	Submission, Representation, SubmissionFile + OJS Issue
     *
     * @return boolean
     * 	false, if the pub id contains an unresolved pattern i.e. '%' or
     * 	if the custom suffix is empty i.e. the pub id null.
     */
    public function canBeAssigned($pubObject)
    {
        // Has the pub id already been assigned.
        $pubIdType = $this->getPubIdType();
        $storedPubId = $pubObject->getStoredPubId($pubIdType);
        if ($storedPubId) {
            return false;
        }
        // Get the pub id.
        $pubId = $this->getPubId($pubObject);
        // Is the custom suffix empty i.e. the pub id null.
        if (!$pubId) {
            return false;
        }
        // Does the suffix contain unresolved pattern.
        $containPatterns = strpos($pubId, '%') !== false;
        return !$containPatterns;
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for SchemaDAO-backed entities only.
     *
     * @see PKPPubIdPlugin::getAdditionalFieldNames()
     *
     * @param $hookName string `Schema::get::publication`
     * @param $params array
     */
    public function addToSchema($hookName, $params)
    {
        $schema = & $params[0];
        foreach (array_merge($this->getFormFieldNames(), $this->getDAOFieldNames()) as $fieldName) {
            $schema->properties->{$fieldName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }
        return false;
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for non-SchemaDAO-backed entities only.
     *
     * @see PKPPubIdPlugin::addToSchema()
     *
     * @param $hookName string
     * @param $params array
     */
    public function getAdditionalFieldNames($hookName, $params)
    {
        $fields = & $params[1];
        foreach (array_merge($this->getFormFieldNames(), $this->getDAOFieldNames()) as $fieldName) {
            $fields[] = $fieldName;
        }
        return false;
    }

    /**
     * Return the object type.
     *
     * @param $pubObject object
     *
     * @return string?
     */
    public function getPubObjectType($pubObject)
    {
        $allowedTypes = $this->getPubObjectTypes();
        $pubObjectType = null;
        foreach ($allowedTypes as $type => $fqcn) {
            if ($pubObject instanceof $fqcn) {
                $pubObjectType = $type;
                break;
            }
        }
        if (is_null($pubObjectType)) {
            // This must be a dev error, so bail with an assertion.
            assert(false);
            return null;
        }
        return $pubObjectType;
    }

    /**
     * Set and store a public identifier.
     *
     * @param $pubObject object
     * @param $pubId string
     */
    public function setStoredPubId(&$pubObject, $pubId)
    {
        $dao = $pubObject->getDAO();
        $dao->changePubId($pubObject->getId(), $this->getPubIdType(), $pubId);
        $pubObject->setStoredPubId($this->getPubIdType(), $pubId);
    }


    //
    // Public API
    //
    /**
     * Check for duplicate public identifiers.
     *
     * Checks to see if a pubId has already been assigned to any object
     * in the context.
     *
     * @param $pubId string
     * @param $pubObjectType string Class name of the pub object being checked
     * @param $excludeId integer This object id will not be checked for duplicates
     * @param $contextId integer
     *
     * @return boolean
     */
    public function checkDuplicate($pubId, $pubObjectType, $excludeId, $contextId)
    {
        foreach ($this->getPubObjectTypes() as $type => $fqcn) {
            if ($type === 'Publication') {
                $typeDao = DAORegistry::getDAO('PublicationDAO'); /** @var PublicationDAO $typeDao */
            } elseif ($type === 'Representation') {
                $typeDao = Application::getRepresentationDAO();
            } elseif ($type === 'SubmissionFile') {
                $typeDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $typeDao */
            }
            $excludeTypeId = $type === $pubObjectType ? $excludeId : null;
            if (isset($typeDao) && $typeDao->pubIdExists($this->getPubIdType(), $pubId, $excludeTypeId, $contextId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the context object.
     *
     * @param $contextId integer
     *
     * @return object Context
     */
    public function getContext($contextId)
    {
        assert(is_numeric($contextId));

        // Get the context object from the context (optimized).
        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        $context = $router->getContext($request);
        if ($context && $context->getId() == $contextId) {
            return $context;
        }

        // Fall back the database.
        $contextDao = Application::getContextDAO();
        return $contextDao->getById($contextId);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\plugins\PKPPubIdPlugin', '\PKPPubIdPlugin');
}
