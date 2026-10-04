<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use Macrolab\Audit;
use Macrolab\Auth;
use Macrolab\Csrf;
use Macrolab\Csv;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Time\Projects;
use Macrolab\Session;
use Macrolab\Time\TimeEntries;
use Macrolab\Time\TimeEntry;
use Macrolab\Time\TimeFilter;
use Macrolab\Time\TimeRules;
use Macrolab\Users;
use Macrolab\View;
use RuntimeException;

/**
 * The managing side of time registration: the project list the administrator
 * maintains, and a read-only view of what everyone has logged, with its CSV
 * export. Lab managers get the overview and the export too, at /time/overview;
 * the project list stays the administrator's.
 *
 * Read-only is the point. There is no approval step and no editing of anyone
 * else's timesheet - see TimeEntryPolicy, which refuses everyone but the
 * entry's owner deliberately.
 *
 * Separate from AdminController because that class is already long enough that
 * finding anything in it is work.
 */
final class AdminTimeController
{
    public function projects(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->handleProjectAction($request);

                return Response::redirect('/admin/projects');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/projects', [
            'title'    => 'Activities',
            'projects' => Projects::all(),
            'error'    => $error,
        ], $error !== null ? 422 : 200);
    }

    public function entries(Request $request): Response
    {
        Auth::requireTimeOverview();

        $filter = TimeFilter::fromRequest($request);

        return View::page('admin/time', [
            'title'     => 'Time overview',
            // /admin/time for the administrator, /time/overview for a lab
            // manager: the filter and the export links stay on the same path.
            'basePath'  => $request->path === '/time/overview' ? '/time/overview' : '/admin/time',
            'filter'    => $filter,
            'entries'   => TimeEntries::search($filter),
            'totals'    => TimeEntries::totals($filter),
            'byProject' => TimeEntries::totalsByProject($filter),
            'people'    => Users::listAll(),
            'projects'  => Projects::all(),
        ]);
    }

    public function export(Request $request): Response
    {
        Auth::requireTimeOverview();

        $filter = TimeFilter::fromRequest($request);
        $entries = TimeEntries::search($filter, 5000);

        // Two flavours of the same rows. Semicolons with a decimal comma are
        // what Excel with Dutch and most European regional settings splits and
        // sums; commas with a decimal point suit everything else.
        $semicolon = $request->query('sep') === 'semicolon';
        $separator = $semicolon ? ';' : ',';
        $decimal = $semicolon ? ',' : '.';

        // A bulk read of who worked how long on what. The audit log is the
        // existing mechanism for recording exactly that.
        Audit::log('time_exported', 'time_entry', null, [
            'from'      => $filter->from->format('Y-m-d'),
            'to'        => $filter->to->format('Y-m-d'),
            'user'      => $filter->userId,
            'project'   => $filter->projectId,
            'rows'      => count($entries),
            'separator' => $semicolon ? 'semicolon' : 'comma',
        ]);

        $body = Csv::fromRows(
            ['date', 'netid', 'name', 'activity', 'activity_code', 'hours', 'minutes', 'note', 'entry_id'],
            array_map(
                static fn (TimeEntry $e): array => [
                    $e->workedOnDate(),
                    $e->ownerNetid,
                    $e->ownerName,
                    $e->projectName,
                    $e->projectCode,
                    // Both: the decimal for a spreadsheet to sum, the integer
                    // because it is the exact stored value.
                    TimeRules::decimalHours($e->minutes, $decimal),
                    $e->minutes,
                    $e->note,
                    $e->id,
                ],
                $entries
            ),
            $separator
        );

        return Response::download($body, $filter->filenameStem() . '.csv');
    }

    private function handleProjectAction(Request $request): void
    {
        $action = $request->post('action', '') ?? '';

        if ($action === 'add') {
            $project = Projects::create(
                $request->post('name', '') ?? '',
                $request->post('code'),
                $request->post('description'),
            );

            Audit::log('project_added', 'project', $project->id,
                ['name' => $project->name, 'code' => $project->code]);
            Session::flash('success', 'Time can now be logged against ' . $project->name . '.');

            return;
        }

        $projectId = (int) ($request->post('project_id', '0') ?? '0');
        $project = $projectId > 0 ? Projects::find($projectId) : null;

        if ($project === null) {
            throw new RuntimeException('That activity no longer exists.');
        }

        switch ($action) {
            case 'update':
                Projects::update(
                    $projectId,
                    $request->post('name', '') ?? '',
                    $request->post('code'),
                    $request->post('description'),
                );
                Audit::log('project_updated', 'project', $projectId, ['name' => $project->name]);
                Session::flash('success', 'Saved.');
                break;

            case 'retire':
                Projects::setActive($projectId, false);
                Audit::log('project_retired', 'project', $projectId, ['name' => $project->name]);
                Session::flash(
                    'success',
                    'No more time can be logged against ' . $project->name
                    . '. The hours already on it are untouched.'
                );
                break;

            case 'reactivate':
                Projects::setActive($projectId, true);
                Audit::log('project_reactivated', 'project', $projectId, ['name' => $project->name]);
                Session::flash('success', 'Time can be logged against ' . $project->name . ' again.');
                break;

            case 'delete':
                $entries = Projects::countEntries($projectId);

                if ($entries > 0) {
                    throw new RuntimeException(
                        $project->name . ' has ' . $entries . ' time '
                        . ($entries === 1 ? 'entry' : 'entries')
                        . ' on record. Retire it instead of deleting it, so the hours keep their activity.'
                    );
                }

                Audit::log('project_deleted', 'project', $projectId,
                    ['name' => $project->name, 'code' => $project->code]);
                Projects::delete($projectId);
                Session::flash('success', $project->name . ' has been removed.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }
}
