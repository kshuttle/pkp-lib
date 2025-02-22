<?php

/**
 * @file api/v1/_submissions/PKPBackendSubmissionsHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPBackendSubmissionsHandler
 * @ingroup api_v1_backend
 *
 * @brief Handle API requests for backend operations.
 *
 */

use APP\core\Services;
use PKP\handler\APIHandler;
use PKP\security\authorization\ContextAccessPolicy;

use PKP\security\Role;

abstract class PKPBackendSubmissionsHandler extends APIHandler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $rootPattern = '/{contextPath}/api/{version}/_submissions';
        $this->_endpoints = array_merge_recursive($this->_endpoints, [
            'GET' => [
                [
                    'pattern' => "{$rootPattern}",
                    'handler' => [$this, 'getMany'],
                    'roles' => [
                        Role::ROLE_ID_SITE_ADMIN,
                        Role::ROLE_ID_MANAGER,
                        Role::ROLE_ID_SUB_EDITOR,
                        Role::ROLE_ID_AUTHOR,
                        Role::ROLE_ID_REVIEWER,
                        Role::ROLE_ID_ASSISTANT,
                    ],
                ],
            ],
            'DELETE' => [
                [
                    'pattern' => "{$rootPattern}/{submissionId:\d+}",
                    'handler' => [$this, 'delete'],
                    'roles' => [
                        Role::ROLE_ID_SITE_ADMIN,
                        Role::ROLE_ID_MANAGER,
                        Role::ROLE_ID_AUTHOR,
                    ],
                ],
            ],
        ]);
        parent::__construct();
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Get a list of submissions according to passed query parameters
     *
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     *
     * @return Response
     */
    public function getMany($slimRequest, $response, $args)
    {
        $request = $this->getRequest();
        $currentUser = $request->getUser();
        $context = $request->getContext();

        // Merge query params over default params
        $defaultParams = [
            'count' => 20,
            'offset' => 0,
        ];

        // Anyone not a manager or site admin can only access their assigned
        // submissions
        $userRoles = $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        $canAccessUnassignedSubmission = !empty(array_intersect([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], $userRoles));
        if (!$canAccessUnassignedSubmission) {
            $defaultParams['assignedTo'] = [$currentUser->getId()];
        }

        $params = array_merge($defaultParams, $slimRequest->getQueryParams());

        // Process query params to format incoming data as needed
        foreach ($params as $param => $val) {
            switch ($param) {

                // Always convert status and stageIds to array
                case 'status':
                case 'stageIds':
                case 'assignedTo':
                    if (is_string($val)) {
                        $val = explode(',', $val);
                    } elseif (!is_array($val)) {
                        $val = [$val];
                    }
                    $params[$param] = array_map('intval', $val);
                    // Special case: assignedTo can be -1 for unassigned
                    if ($param == 'assignedTo' && $val == [-1]) {
                        $params[$param] = -1;
                    }
                    break;

                case 'daysInactive':
                case 'offset':
                    $params[$param] = (int) $val;
                    break;

                // Enforce a maximum count to prevent the API from crippling the
                // server
                case 'count':
                    $params[$param] = min(100, (int) $val);
                    break;

                case 'orderBy':
                    if (!in_array($val, ['dateSubmitted', 'dateLastActivity', 'lastModified', 'title'])) {
                        unset($params[$param]);
                    }
                    break;

                case 'orderDirection':
                    $params[$param] = $val === 'ASC' ? $val : 'DESC';
                    break;

                case 'isIncomplete':
                case 'isOverdue':
                    $params[$param] = true;
            }
        }

        $params['contextId'] = $context->getId();

        \HookRegistry::call('API::_submissions::params', [&$params, $slimRequest, $response]);

        // Prevent users from viewing submissions they're not assigned to,
        // except for journal managers and admins.
        if (!$canAccessUnassignedSubmission && !in_array($currentUser->getId(), $params['assignedTo'])) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.requestedOthersUnpublishedSubmissions');
        }

        $submissionsIterator = Services::get('submission')->getMany($params);
        $items = [];
        if (count($submissionsIterator)) {
            $propertyArgs = [
                'request' => $request,
                'slimRequest' => $slimRequest,
            ];
            foreach ($submissionsIterator as $submission) {
                $items[] = Services::get('submission')->getBackendListProperties($submission, $propertyArgs);
            }
        }
        $data = [
            'items' => $items,
            'itemsMax' => Services::get('submission')->getMax($params),
        ];

        return $response->withJson($data);
    }

    /**
     * Delete a submission
     *
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     * @param array $args arguments
     *
     * @return Response
     */
    public function delete($slimRequest, $response, $args)
    {
        $request = $this->getRequest();
        $context = $request->getContext();
        $submissionId = (int) $args['submissionId'];
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $submission = $submissionDao->getById($submissionId);

        if (!$submission) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
        }

        if ($context->getId() != $submission->getContextId()) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.deleteSubmissionOutOfContext');
        }

        import('classes.core.Services');
        if (!Services::get('submission')->canCurrentUserDelete($submission)) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.unauthorizedDeleteSubmission');
        }

        Services::get('submission')->delete($submission);

        return $response->withJson(true);
    }
}
