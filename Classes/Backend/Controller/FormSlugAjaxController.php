<?php

declare(strict_types=1);

namespace Wazum\Sluggi\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Wazum\Sluggi\Helper\Configuration;
use Wazum\Sluggi\Helper\PermissionHelper;
use Wazum\Sluggi\Helper\SlugHelper as SluggiSlugHelper;
use function array_pop;
use function explode;
use function implode;
use function in_array;
use function str_replace;
use function substr;

/**
 * Class FormSlugAjaxController
 *
 * @author Wolfgang Klinger <wolfgang@wazum.com>
 */
class FormSlugAjaxController extends \TYPO3\CMS\Backend\Controller\FormSlugAjaxController
{
    /**
     * @throws RuntimeException
     * @throws SiteNotFoundException
     */
    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkRequest($request);
        $queryParameters = $request->getParsedBody() ?? [];
        $tableName = $queryParameters['tableName'];

        if ('pages' !== $tableName || PermissionHelper::hasFullPermission()) {
            return parent::suggestAction($request);
        }

        return $this->modifiedSuggestAction($request);
    }

    /**
     * @throws SiteNotFoundException
     */
    protected function modifiedSuggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $allowOnlyLastSegment = (bool) Configuration::get('last_segment_only');
        $queryParameters = $request->getParsedBody() ?? [];
        $values = $queryParameters['values'];
        $mode = $queryParameters['mode'];
        $tableName = $queryParameters['tableName'];
        $pid = (int) $queryParameters['pageId'];
        $parentPageId = (int) $queryParameters['parentPageId'];
        $recordId = (int) $queryParameters['recordId'];
        $languageId = (int) $queryParameters['language'];
        $fieldName = $queryParameters['fieldName'];

        $fieldConfig = $GLOBALS['TCA'][$tableName]['columns'][$fieldName]['config'] ?? [];
        if (empty($fieldConfig)) {
            throw new RuntimeException('No valid field configuration for table ' . $tableName . ' field name ' . $fieldName . ' found.', 1535379534);
        }

        $evalInfo = !empty($fieldConfig['eval']) ? GeneralUtility::trimExplode(',', $fieldConfig['eval'], true) : [];
        $hasToBeUniqueInSite = in_array('uniqueInSite', $evalInfo, true);
        $hasToBeUniqueInPid = in_array('uniqueInPid', $evalInfo, true);

        $hasConflict = false;

        $recordData = $values;
        $recordData['pid'] = $pid;
        if (!empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
            $recordData[$GLOBALS['TCA'][$tableName]['ctrl']['languageField']] = $languageId;
        }

        /** @var SlugHelper $slug */
        $slug = GeneralUtility::makeInstance(SlugHelper::class, $tableName, $fieldName, $fieldConfig);
        if ('auto' === $mode) {
            // New page - Feed incoming values to generator
            $proposal = $slug->generate($recordData, $pid);
        } elseif ('recreate' === $mode) {
            $proposal = $slug->generate($recordData, $parentPageId);
        } elseif ('manual' === $mode) {
            // Existing record - Fetch full record and only validate against the new "slug" field.
            $proposal = $slug->sanitize($values['manual']);
            if ($allowOnlyLastSegment) {
                $generatedSegments = explode('/', $slug->generate($recordData, $parentPageId));
                array_pop($generatedSegments);
                $proposal = implode('/', $generatedSegments) . '/' . str_replace('/', '-', substr($proposal, 1));
            }
        } else {
            throw new RuntimeException('mode must be either "auto", "recreate" or "manual"', 1535835666);
        }

        $state = RecordStateFactory::forName($tableName)
            ->fromArray($recordData, $pid, $recordId);
        if ($hasToBeUniqueInSite && !$slug->isUniqueInSite($proposal, $state)) {
            $hasConflict = true;
            $proposal = $slug->buildSlugForUniqueInSite($proposal, $state);
        }
        if ($hasToBeUniqueInPid && !$slug->isUniqueInPid($proposal, $state)) {
            $hasConflict = true;
            $proposal = $slug->buildSlugForUniqueInPid($proposal, $state);
        }

        $mountRootPage = PermissionHelper::getTopmostAccessiblePage($pid);
        $inaccessibleSlugSegments = null;
        if (null !== $mountRootPage) {
            $inaccessibleSlugSegments = SluggiSlugHelper::getSlug($mountRootPage['pid'], $languageId);
        }

        return new JsonResponse([
            'hasConflicts' => !$mode && $hasConflict,
            'manual' => $values['manual'] ?? '',
            'proposal' => $proposal,
            'inaccessibleSegments' => $inaccessibleSlugSegments,
            'lastSegmentOnly' => $allowOnlyLastSegment
        ]);
    }
}
