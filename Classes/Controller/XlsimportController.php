<?php

declare(strict_types=1);

namespace SUDHAUS7\Xlsimport\Controller;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\RowIterator;
use SUDHAUS7\Xlsimport\Service\DisallowedTablesService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class XlsimportController
 * @package SUDHAUS7\Xlsimport\Controller
 */
class XlsimportController extends ActionController
{
    /**
     * Backend Template Container
     *
     * @var string
     */
    protected $defaultViewObjectName = BackendTemplateView::class;
    /**
     * @var LanguageService
     */
    protected $languageService;
    /**
     * @var string[]
     */
    protected $disallowedFields = [
        't3ver_oid',
        'tstamp',
        'crdate',
        'cruser_id',
        'hidden',
        'deleted',
        't3ver_id',
        't3ver_wsid',
        't3ver_label',
        't3ver_state',
        't3ver_stage',
        't3ver_count',
        't3ver_tstamp',
        't3ver_move_id',
        't3_origuid',
        'l10n_diffsource',
        'l10n_source'
    ];

    /**
     * @var ResourceFactory
     */
    protected $resourceFactory;

    /**
     * @param ResourceFactory $resourceFactory
     */
    public function injectResourceFactory(ResourceFactory $resourceFactory): void
    {
        $this->resourceFactory = $resourceFactory;
    }

    /**
     * XlsimportController constructor.
     * @throws \TYPO3\CMS\Extbase\Object\Exception
     */
    public function initializeObject(): void
    {
        $pageRenderer = $this->getPageRenderer();
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Xlsimport/Importer');

        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * @throws ExtensionConfigurationPathDoesNotExistException
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     */
    public function indexAction(): void
    {
        $page = (int)GeneralUtility::_GET('id');
        /**
         * @deprecated, don't use TypoScript module setup anymore
         * Use PageTSConfig or Extension setup instead
         * will be removed in future version
         */
        $tempTables = GeneralUtility::trimExplode(',', $this->settings['allowedTables']);

        $pageTS = BackendUtility::getPagesTSconfig($page);
        if (isset($pageTS['module.']['tx_xlsimport.']['settings.']['allowedTables'])) {
            $tempTables = array_merge(
                $tempTables,
                GeneralUtility::trimExplode(
                    ',',
                    $pageTS['module.']['tx_xlsimport.']['settings.']['allowedTables']
                )
            );
        }
        if ($extConfTempTables
            = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('xlsimport', 'tables')) {
            $tempTables = array_merge(
                $tempTables,
                GeneralUtility::trimExplode(',', $extConfTempTables)
            );
        }
        $tempTables = array_unique($tempTables);
        $allowedTables = [];
        foreach ($tempTables as $tempTable) {
            if (in_array($tempTable, DisallowedTablesService::$disallowedTcaTables)) {
                continue;
            }
            if (array_key_exists($tempTable, $GLOBALS['TCA'])) {
                $label = $GLOBALS['TCA'][$tempTable]['ctrl']['title'];
                if ($page === 0) {
                    if ($GLOBALS['TCA'][$tempTable]['ctrl']['rootLevel'] === 1
                        || $GLOBALS['TCA'][$tempTable]['ctrl']['rootLevel'] === -1) {
                        $allowedTables[$tempTable] = $this->getLang()->sL($label) ?: $label;
                    }
                } else if (
                    $GLOBALS['TCA'][$tempTable]['ctrl']['rootLevel'] === 0
                    || $GLOBALS['TCA'][$tempTable]['ctrl']['rootLevel'] === -1
                    || !isset($GLOBALS['TCA'][$tempTable]['ctrl']['rootLevel'])
                ) {
                    $allowedTables[$tempTable] = $this->getLang()->sL($label) ?: $label;
                }
            }
        }

        $assignedValues = [
            'page' => $page,
            'allowedTables' => $allowedTables
        ];
        $this->view->assignMultiple($assignedValues);
    }

    /**
     * @throws Exception
     * @throws NoSuchArgumentException
     */
    public function uploadAction(): void
    {
        $page = GeneralUtility::_GET('id');

        $deleteOldRecords = (bool)$this->request->getArgument('deleteRecords');
        $file = $this->request->getArgument('file');
        $table = $this->request->getArgument('table');
        if ($deleteOldRecords) {
            /** @var DataHandler $tce */
            $tce = GeneralUtility::makeInstance(DataHandler::class);

            $db = GeneralUtility::makeInstance(ConnectionPool::class);
            $builder = $db->getQueryBuilderForTable($table);
            $ids = $builder->select('*')->from($table)->where(
                $builder->expr()->eq('pid', $page)
            )->execute()->fetchAll();
            $cmd = [];
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $cmd[$table][$id['uid']]['delete'] = 1;
                }
                $tce->start([], $cmd);
                $tce->process_datamap();
                $tce->process_cmdmap();
            }
        }

        $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($this->settings['uploadFolder']);
        $newFile = $folder->addFile($file['tmp_name'], $file['name'],$this->settings['duplicationBehavior'] ?? DuplicationBehavior::RENAME);

        $list = $this->getList($newFile);
        $uidConfig = [
            'uid' => [
                'label' => 'uid'
            ]
        ];
        $tca = array_merge($uidConfig, $GLOBALS['TCA'][$table]['columns']);

        if(!array_key_exists('pid',$GLOBALS['TCA'][$table]['columns'])) {
            $pidConfig = [
                'pid' => [
                    'label' => 'pid'
                ]
            ];
            $tca = array_merge($uidConfig, $pidConfig, $GLOBALS['TCA'][$table]['columns']);
        }

        $hasPasswordField = false;
        $passwordFields = [];

        foreach ($tca as $field => &$column) {
            if (in_array($field, $this->disallowedFields, true)) {
                unset($tca[$field]);
            } else {
                try {
                    $label = $this->languageService->sL($column['label']);
                } catch (InvalidArgumentException $e) {
                    $label = $column['label'];
                }
                if (empty($label)) {
                    $label = '[' . $field . ']';
                }
                if ($field === 'uid') {
                    $label = $this->languageService->sL('LLL:EXT:xlsimport/Resources/Private/Language/locallang.xlf:uid');
                }
                if ($field === 'pid') {
                    $label = $this->languageService->sL('LLL:EXT:xlsimport/Resources/Private/Language/locallang.xlf:pid');
                }
                $column['label'] = $label;

                if (isset($column['config']['eval']) && in_array('password', GeneralUtility::trimExplode(',', $column['config']['eval']))) {
                    $hasPasswordField = true;
                    $passwordFields[] = $field;
                }
            }
        }
        unset($column);

        $assignedValues = [
            'fields' => $tca,
            'data' => $list['data'],
            'page' => $page,
            'table' => $table,
            'hasPasswordField' => $hasPasswordField,
            'passwordFields' => implode(',', $passwordFields),
            'addInlineSettings' => [
                'FormEngine' => [
                    'formName' => 'importData'
                ]
            ]
        ];
        $this->view->assignMultiple($assignedValues);
    }

    /**
     * @throws NoSuchArgumentException
     * @throws StopActionException
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function importAction(): void
    {
        $page = GeneralUtility::_GET('id');
        $table = $this->request->getArgument('table');
        if (!$table || !array_key_exists($table, $GLOBALS['TCA'])) {
            $this->redirect('index');
            exit;
        }
        /** @var array $fields */
        $fields = $this->request->getArgument('fields');
        $overrides = [];
        if ($this->request->hasArgument('overrides')) {
            $overrides = $this->request->getArgument('overrides');
        }

        $passwordOverride = (bool)$this->request->getArgument('passwordOverride');
        $passwordFields = GeneralUtility::trimExplode(',', $this->request->getArgument('passwordFields'));

        /** @var array $imports */
        $imports = json_decode($this->request->getArgument('dataset'), true);
        $a = [];
        foreach ($imports as $import) {
            $s = sprintf('%s=%s', $import['name'], urlencode($import['value']));
            $temp = [];
            parse_str($s, $temp);
            foreach ($temp['tx_xlsimport_web_xlsimporttxxlsimport']['dataset'] as $k => $v) {
                foreach ($v as $key => $value) {
                    $a[$k][$key] = $value;
                }
            }
        }
        $imports = $a;

        // unset all fields not assigned to a TCA field
        foreach ($fields as $key => $field) {
            if (empty($field)) {
                unset($fields[$key]);
            }
            if (in_array($field, $this->disallowedFields, true)) {
                unset($fields[$key]);
            }
        }
        // get override field and take a look inside fieldlist, if defined
        foreach ($overrides as $key => $override) {
            if (empty($override) | in_array($override, $fields, true)) {
                unset($overrides[$key]);
            }
        }

        if ($passwordOverride) {
            foreach ($passwordFields as $key => $passwordField) {
                if (in_array($passwordField, $fields, true)) {
                    unset($passwordFields[$key]);
                }
            }
        } else {
            $passwordFields = [];
        }

        $inserts = [
            $table => []
        ];
        foreach ($imports as $import) {
            if ($import['import']) {
                $insertArray = [];
                $update = false;
                foreach ($fields as $key => $field) {
                    if ($field === 'uid') {
                        if (!empty($import[$key])) {
                            $update = true;
                        }
                    } else {
                        $insertArray[$field] = $import[$key];
                    }
                    if (!isset($insertArray['pid'])) {
                        $insertArray['pid'] = $page;
                    }
                }
                foreach ($overrides as $key => $override) {
                    $insertArray[$key] = $override;
                }

                foreach ($passwordFields as $passwordField) {
                    $insertArray[$passwordField] = md5(sha1(microtime()));
                }

                $inserts[$table][$update ? $import['uid'] : uniqid('NEW_', true)] = $insertArray;
            }
        }
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][self::class]['Hooks'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][self::class]['Hooks'] as $_classRef) {
                $hookObj = GeneralUtility::makeInstance($_classRef);
                if (method_exists($hookObj, 'manipulateRelations')) {
                    $hookObj->manipulateRelations($inserts, $table, $this);
                }
            }
        }
        /** @var DataHandler $tce */
        $tce = GeneralUtility::makeInstance(DataHandler::class);
        $tce->start($inserts, []);
        $tce->process_datamap();
        $lang = $this->getLang();
        /** @var FlashMessage $message */
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            $lang->sL('LLL:EXT:xlsimport/Resources/Private/Language/locallang.xlf:success'),
            $lang->sL('LLL:EXT:xlsimport/Resources/Private/Language/locallang.xlf:complete'),
            FlashMessage::OK,
            true
        );
        /** @var FlashMessageService $flashMessageService */
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->enqueue($message);
        $this->redirect('index');
    }

    /**
     * @param File $file
     *
     * @return array
     * @throws Exception|NoSuchArgumentException
     */
    protected function getList(File $file): array
    {
        $aList = [];
        $aList['rows'] = 0;
        $aList['cols'] = 0;
        $aList['data'] = [];
        $fileName = $file->getForLocalProcessing();
        if (is_file($fileName)) {

            $inputFileType = IOFactory::identify($fileName);

            if ($inputFileType === 'Csv') {
                $oReader = new Csv();
                $encoding = (bool)$this->request->getArgument('encoding');

                if ($encoding === true) {
                    $oReader->setInputEncoding('CP1252');
                }
            }
            else {
                $oReader = IOFactory::createReaderForFile($fileName);
            }

            if ($oReader->canRead($fileName)) {
                //$oReader->getReadDataOnly();
                $xls = $oReader->load($fileName);
                $xls->setActiveSheetIndex(0);
                $sheet = $xls->getActiveSheet();
                $rowI = new RowIterator($sheet, 1);

                $rowcount = 1;
                $colcount = 1;
                foreach ($rowI as $k => $row) {
                    $rowcount++;
                    $cell = new RowCellIterator($sheet, 1, 'A');

                    $cell->setIterateOnlyExistingCells(true);

                    $tmpcolcount = 0;
                    foreach ($cell as $ck => $ce) {
                        $tmpcolcount++;
                    }
                    if ($tmpcolcount > $colcount) {
                        $colcount = $tmpcolcount;
                    }
                }

                $aList['rows'] = $rowcount;
                $aList['cols'] = $colcount;

                for ($y = 1; $y < $rowcount; $y++) {
                    for ($x = 1; $x <= $colcount; $x++) {
                        $aList['data'][$y][$x] = $sheet->getCellByColumnAndRow($x, $y)->getValue();
                    }
                }

                unset($rowI, $sheet, $xls, $oReader);
            }
        }
        return ($aList);
    }

    /**
     * @return LanguageService
     */
    protected function getLang(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return object|PageRenderer
     */
    protected function getPageRenderer()
    {
        return GeneralUtility::makeInstance(PageRenderer::class);
    }
}
