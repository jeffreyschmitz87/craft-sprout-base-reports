<?php
/**
 * @link      https://sprout.barrelstrengthdesign.com/
 * @copyright Copyright (c) Barrel Strength Design LLC
 * @license   http://sprout.barrelstrengthdesign.com/license
 */

namespace barrelstrength\sproutbasereports\controllers;

use barrelstrength\sproutbase\SproutBase;
use barrelstrength\sproutbasereports\base\DataSource;
use barrelstrength\sproutbasereports\elements\Report;
use barrelstrength\sproutbasereports\models\ReportGroup;
use barrelstrength\sproutbasereports\records\Report as ReportRecord;
use barrelstrength\sproutbasereports\services\DataSources;
use barrelstrength\sproutbasereports\SproutBaseReports;
use barrelstrength\sproutbasereports\models\Settings;
use barrelstrength\sproutreports\SproutReports;
use Craft;
use craft\errors\ElementNotFoundException;
use craft\errors\MissingComponentException;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\assets\cp\CpAsset;
use craft\web\Controller;
use Throwable;
use yii\base\Exception;
use yii\base\ExitException;
use yii\base\InvalidConfigException;
use yii\db\StaleObjectException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReportsController extends Controller
{
    private $permissions = [];

    private $dataSourceBaseUrl;

    public function init()
    {
        $this->permissions = SproutBase::$app->settings->getPluginPermissions(new Settings(), 'sprout-reports');

        // Only use dataSourceBaseUrl variable in template routes, segments won't be accurate in action requests
        if (!Craft::$app->getRequest()->getIsActionRequest()) {
            $segmentOne = Craft::$app->getRequest()->getSegment(1);
            $segmentTwo = Craft::$app->getRequest()->getSegment(2);

            $this->dataSourceBaseUrl = $segmentOne.'/'.$segmentTwo.'/';
        }

        parent::init();
    }

    /**
     * @param string $viewContext
     * @param string $pluginHandle
     * @param null   $groupId
     * @param bool   $hideSidebar
     *
     * @return Response
     * @throws Exception
     * @throws ForbiddenHttpException
     */
    public function actionReportsIndexTemplate(string $viewContext = DataSources::DEFAULT_VIEW_CONTEXT, $pluginHandle = 'sprout-reports', $groupId = null, $hideSidebar = false): Response
    {
        $this->requirePermission($this->permissions['sproutReports-viewReports']);

        $dataSources = SproutBaseReports::$app->dataSources->getInstalledDataSources($viewContext);

        if ($groupId !== null) {
            $reports = SproutBaseReports::$app->reports->getReportsByGroupId($groupId);
        } else {
            $reports = SproutBaseReports::$app->reports->getAllReports();
        }

        $newReportOptions = [];

        foreach ($dataSources as $dataSource) {

            /** @var $dataSource DataSource */
            $dataSource->baseUrl = $this->dataSourceBaseUrl;

            // Ignore the allowNew setting if we're displaying a Reports integration
            if ($dataSource->allowNew || $viewContext !== DataSources::DEFAULT_VIEW_CONTEXT) {
                $newReportOptions[] = [
                    'name' => $dataSource::displayName(),
                    'url' => $dataSource->getUrl($dataSource->id.'/new')
                ];
            }
        }

        return $this->renderTemplate('sprout-base-reports/reports/index', [
            'dataSources' => $dataSources,
            'groupId' => $groupId,
            'reports' => $reports,
            'newReportOptions' => $newReportOptions,
            'viewReportsPermission' => $this->permissions['sproutReports-viewReports'],
            'editReportsPermission' => $this->permissions['sproutReports-editReports'],
            'hideSidebar' => $hideSidebar,
            'viewContext' => $viewContext,
            'baseDataSourceUrl' => $this->dataSourceBaseUrl,
            'pluginHandle' => $pluginHandle
        ]);
    }

    /**
     * @param string      $viewContext
     * @param string      $pluginHandle
     * @param Report|null $report
     * @param int|null    $reportId
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     */
    public function actionResultsIndexTemplate(string $viewContext = DataSources::DEFAULT_VIEW_CONTEXT, $pluginHandle = 'sprout-reports',  Report $report = null, int $reportId = null): Response
    {
        $this->requirePermission($this->permissions['sproutReports-viewReports']);

        if ($report === null) {
            $report = SproutBaseReports::$app->reports->getReport($reportId);
        }

        if (!$report) {
            throw new NotFoundHttpException('Report not found.');
        }

        $dataSource = $report->getDataSource();

        if (!$dataSource) {
            throw new NotFoundHttpException('Data Source not found.');
        }

        $labels = $dataSource->getDefaultLabels($report);

        // Set the base URL so we can use the $dataSource->getUrl method
        $dataSource->baseUrl = $this->dataSourceBaseUrl;

        $reportIndexUrl = $dataSource->getUrl($report->groupId);

        if ($viewContext !== DataSources::DEFAULT_VIEW_CONTEXT) {
            $reportIndexUrl = $dataSource->getUrl($dataSource->id);
        }

        $values = $dataSource->getResults($report);

        if (empty($labels) && !empty($values)) {
            $firstItemInArray = reset($values);
            $labels = array_keys($firstItemInArray);
        }

        $this->getView()->registerAssetBundle(CpAsset::class);

        /** @var SproutReports $plugin */
        $plugin = Craft::$app->getPlugins()->getPlugin('sprout-reports');

        return $this->renderTemplate('sprout-base-reports/results/index', [
            'report' => $report,
            'dataSource' => $dataSource,
            'labels' => $labels,
            'values' => $values,
            'reportIndexUrl' => $reportIndexUrl,
            'redirectUrl' => Craft::$app->getRequest()->getSegment(1).'/reports/view/'.$reportId,
            'viewReportsPermission' => $this->permissions['sproutReports-viewReports'],
            'editReportsPermission' => $this->permissions['sproutReports-editReports'],
            'settings' => $plugin ? $plugin->getSettings() : null,
            'baseDataSourceUrl' => $this->dataSourceBaseUrl,
            'pluginHandle' => $pluginHandle
        ]);
    }

    /**
     * @param string      $viewContext
     * @param string      $dataSourceId
     * @param Report|null $report
     * @param int|null    $reportId
     *
     * @return Response
     * @throws NotFoundHttpException
     * @throws Exception
     * @throws ForbiddenHttpException
     */
    public function actionEditReportTemplate(string $viewContext = DataSources::DEFAULT_VIEW_CONTEXT, $pluginHandle = 'sprout-reports',  string $dataSourceId = null, Report $report = null, int $reportId = null): Response
    {
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $reportElement = new Report();
        $reportElement->enabled = 1;

        if ($report !== null) {
            $reportElement = $report;
        } elseif ($reportId !== null) {
            $reportElement = SproutBaseReports::$app->reports->getReport($reportId);
        }

        // This is for creating new report
        if ($dataSourceId !== null) {
            $reportElement->dataSourceId = $dataSourceId;
        }

        $dataSource = $reportElement->getDataSource();

        if (!$dataSource) {
            throw new NotFoundHttpException('Data Source not found.');
        }

        // Set the base URL so we can use the $dataSource->getUrl method
        $dataSource->baseUrl = $this->dataSourceBaseUrl;

        $reportIndexUrl = $dataSource->getUrl($reportElement->groupId);

        if ($viewContext !== DataSources::DEFAULT_VIEW_CONTEXT) {
            $reportIndexUrl = $dataSource->getUrl($dataSource->id);
        }

        // Make sure you navigate to the right plugin page after saving and breadcrumb
        if (Craft::$app->request->getSegment(1) == 'sprout-reports') {
            $reportIndexUrl = UrlHelper::cpUrl('sprout-reports/reports');
        }

        $groups = [];

        if (Craft::$app->getPlugins()->getPlugin('sprout-reports')) {
            $groups = SproutBaseReports::$app->reportGroups->getAllReportGroups();
        }

        return $this->renderTemplate('sprout-base-reports/reports/_edit', [
            'report' => $reportElement,
            'dataSource' => $dataSource,
            'reportIndexUrl' => $reportIndexUrl,
            'groups' => $groups,
            'continueEditingUrl' => $dataSource->getUrl("/$dataSourceId/edit/{id}"),
            'editReportsPermission' => $this->permissions['sproutReports-editReports'],
            'pluginHandle' => $pluginHandle
        ]);
    }

    /**
     * Saves a report query to the database
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws Throwable
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     */
    public function actionUpdateReport()
    {
        $this->requirePostRequest();
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $request = Craft::$app->getRequest();

        $reportElement = new Report();

        $reportId = $request->getBodyParam('reportId');
        $settings = $request->getBodyParam('settings');

        if ($reportId && $settings) {
            $reportElement = SproutBaseReports::$app->reports->getReport($reportId);

            if (!$reportElement) {
                throw new NotFoundHttpException('No report exists with the ID: '.$reportId);
            }

            $reportElement->settings = is_array($settings) ? $settings : [];

            if (SproutBaseReports::$app->reports->saveReport($reportElement)) {
                Craft::$app->getSession()->setNotice(Craft::t('sprout-base-reports', 'Query updated.'));

                return $this->redirectToPostedUrl($reportElement);
            }
        }

        // Encode back to object after validation for getResults method to recognize option object
        $reportElement->settings = Json::encode($reportElement->settings);

        Craft::$app->getSession()->setError(Craft::t('sprout-base-reports', 'Could not update report.'));

        // Send the report back to the template
        Craft::$app->getUrlManager()->setRouteParams([
            'report' => $reportElement
        ]);

        return null;
    }

    /**
     * Saves a report query to the database
     *
     * @return null|Response
     * @throws Throwable
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws BadRequestHttpException
     */
    public function actionSaveReport()
    {
        $this->requirePostRequest();
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $report = $this->prepareFromPost();

        if (!Craft::$app->getElements()->saveElement($report)) {
            Craft::$app->getSession()->setError(Craft::t('sprout-base-reports', 'Couldn’t save report.'));

            // Send the report back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'report' => $report
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('sprout-base-reports', 'Report saved.'));

        return $this->redirectToPostedUrl($report);
    }

    /**
     * Deletes a Report
     *
     * @return Response
     * @throws \Exception
     * @throws Throwable
     * @throws StaleObjectException
     * @throws BadRequestHttpException
     */
    public function actionDeleteReport(): Response
    {
        $this->requirePostRequest();
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $reportId = Craft::$app->getRequest()->getBodyParam('id');

        if ($record = ReportRecord::findOne($reportId)) {
            $record->delete();

            Craft::$app->getSession()->setNotice(Craft::t('sprout-base-reports', 'Report deleted.'));

            return $this->redirectToPostedUrl($record);
        }

        throw new NotFoundHttpException('Report not found.');
    }

    /**
     * Saves a Report Group
     *
     * @return Response
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionSaveGroup(): Response
    {
        $this->requirePostRequest();
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $request = Craft::$app->getRequest();

        $groupName = $request->getBodyParam('name');

        $group = new ReportGroup();
        $group->id = $request->getBodyParam('id');
        $group->name = $groupName;

        if (SproutBaseReports::$app->reportGroups->saveGroup($group)) {

            Craft::$app->getSession()->setNotice(Craft::t('sprout-base-reports', 'Report group saved.'));

            return $this->asJson([
                'success' => true,
                'group' => $group->getAttributes(),
            ]);
        }

        return $this->asJson([
            'errors' => $group->getErrors(),
        ]);
    }

    /**
     * Deletes a Report Group
     *
     * @return Response
     * @throws \Exception
     * @throws Throwable
     * @throws StaleObjectException
     * @throws BadRequestHttpException
     */
    public function actionDeleteGroup(): Response
    {
        $this->requirePostRequest();
        //$this->requirePermission($this->permissions['sproutReports-editReports']);

        $groupId = Craft::$app->getRequest()->getBodyParam('id');
        $success = SproutBaseReports::$app->reportGroups->deleteGroup($groupId);

        Craft::$app->getSession()->setNotice(Craft::t('sprout-base-reports', 'Group deleted..'));

        return $this->asJson([
            'success' => $success,
        ]);
    }

    /**
     * Export a Report
     *
     * @throws Exception
     */
    public function actionExportReport()
    {
        //$this->requirePermission($this->permissions['sproutReports-viewReports']);

        $reportId = Craft::$app->getRequest()->getParam('reportId');

        $report = SproutBaseReports::$app->reports->getReport($reportId);
        $settings = Craft::$app->getRequest()->getBodyParam('settings') ?? [];

        if ($report) {
            $dataSource = SproutBaseReports::$app->dataSources->getDataSourceById($report->dataSourceId);

            if ($dataSource) {
                $date = date('Ymd-his');

                // Name the report using the $report toString method that will check both nameFormat and name
                $filename = $report.'-'.$date;

                $dataSource->isExport = true;
                $labels = $dataSource->getDefaultLabels($report, $settings);
                $values = $dataSource->getResults($report, $settings);

                SproutBaseReports::$app->exports->toCsv($values, $labels, $filename);
            }
        }
    }

    /**
     * Returns a report model populated from saved/POSTed data
     *
     * @return Report
     * @throws Exception
     */
    public function prepareFromPost(): Report
    {
        $request = Craft::$app->getRequest();

        $reportId = $request->getBodyParam('id');

        if ($reportId) {
            $report = SproutBaseReports::$app->reports->getReport($reportId);

            if (!$report) {
                $report->addError('id', Craft::t('sprout-base-reports', 'Could not find a report with id {reportId}', [
                    'reportId' => $reportId
                ]));
            }
        } else {
            $report = new Report();
        }

        $settings = $request->getBodyParam('settings');

        $report->name = $request->getBodyParam('name');
        $report->hasNameFormat = $request->getBodyParam('hasNameFormat');
        $report->nameFormat = $request->getBodyParam('nameFormat');
        $report->handle = $request->getBodyParam('handle');
        $report->description = $request->getBodyParam('description');
        $report->settings = is_array($settings) ? $settings : [];
        $report->dataSourceId = $request->getBodyParam('dataSourceId');
        $report->enabled = $request->getBodyParam('enabled', false);
        $report->groupId = $request->getBodyParam('groupId');

        $dataSource = $report->getDataSource();

        if (!$dataSource) {
            throw new NotFoundHttpException('Date Source not found.');
        }

        $report->allowHtml = $request->getBodyParam('allowHtml', $dataSource->getDefaultAllowHtml());

        return $report;
    }
}
