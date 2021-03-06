<?php

final class SprintReportOpenTasksView extends SprintView {

    protected $viewer;
    private $request;
    private $view;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRequest($request) {
    $this->request = $request;
    return $this;
  }

  public function setView($view) {
    $this->view = $view;
    return $this;
  }

  public function getOpenStatusList() {
    $open_status_list = array();

    foreach (ManiphestTaskStatus::getOpenStatusConstants() as $constant) {
      $open_status_list[] = json_encode((string)$constant);
    }
  return $open_status_list;
  }

  protected function loadViewerHandles(array $phids) {
    return id(new PhabricatorHandleQuery())
        ->setViewer($this->request->getUser())
        ->withPHIDs($phids)
        ->execute();
  }

  public function render() {
    $project_phid = $this->request->getStr('project');
    $project_handle = null;

    if ($project_phid) {
      $phids = array($project_phid);
      $project_handle = $this->getProjectHandle($phids, $project_phid, $this->request);
      $tasks = $this->getOpenTasksforProject($this->viewer, $phids);
    } else {
      $tasks = $this->getOpenTasks($this->viewer);
    }
    $recently_closed = $this->loadRecentlyClosedTasks();

    $date = phabricator_date(time(), $this->viewer);

    $viewer_task_view = new UserOpenTasksView();
    $project_task_view = new ProjectOpenTasksView();

    if (($this->view) == 'user') {
       list($leftover, $leftover_closed, $base_link, $leftover_name,
           $col_header, $header, $result_closed, $result) =
           ($viewer_task_view->execute($tasks, $recently_closed, $date));
    } else if (($this->view) == 'project') {
        list($leftover, $base_link, $leftover_name, $col_header, $header,
            $result_closed, $leftover_closed, $result) =
           ($project_task_view->execute($tasks, $recently_closed, $date));
    } else {
      $result = array();
      $result_closed = array();
      $base_link = null;
      $leftover = array();
      $leftover_closed = array();
      $leftover_name = null;
      $col_header = '';
      $header = '';
    }

    $order = $this->request->getStr('order', 'name');
    list($order, $reverse) = AphrontTableView::parseSort($order);

    require_celerity_resource('aphront-tooltip-css');
    Javelin::initBehavior('phabricator-tooltips', array());

    $rows = $this->buildRowsfromResult($project_handle, $result, $result_closed,
        $base_link, $leftover, $leftover_name, $leftover_closed, $order,
        $reverse);

    list($cname, $cclass) = $this->buildTableColumns($col_header);
    $table = $this->buildOpenTasksTable($rows, $cname, $cclass, $order,
        $reverse);
    $panel = new PHUIObjectBoxView();
    $panel->setHeaderText($header);
    $panel->setTable($table);

    $tokens = array();
    if ($project_handle) {
      $tokens = array($project_handle);
    }
    $filter = $this->renderReportFilters($tokens, $has_window = false,
        $this->request, $this->viewer);

    return array($filter, $panel);
  }

  /**
   * @param integer $total
   */
  private function setSortOrder($row, $order, $total, $oldest_all, $oldest_pri,
                                 $closed, $handle) {
    switch ($order) {
      case 'total':
        $row['sort'] = $total;
        break;
      case 'oldest-all':
        $row['sort'] = $oldest_all;
        break;
      case 'oldest-pri':
        $row['sort'] = $oldest_pri;
        break;
      case 'closed':
        $row['sort'] = count($closed);
        break;
      case 'name':
      default:
        $row['sort'] = $handle ? $handle->getName() : '~';
        break;
    }
  return $row['sort'];
  }

  /**
   * @param string|null $base_link
   * @param PhutilSafeHTML|null $leftover_name
   * @param string $order
   * @param integer $reverse
   */
  private function buildRowsfromResult($project_handle, $result,
                                        $result_closed, $base_link, $leftover,
                                        $leftover_name, $leftover_closed,
                                        $order, $reverse) {
    $phids = array_keys($result);
    $handles = $this->loadViewerHandles($phids);
    $handles = msort($handles, 'getName');

    $rows = array();

    foreach ($handles as $handle) {
      list($tasks, $name, $closed) = $this->setTaskArrays($handle,
          $project_handle, $result, $result_closed, $base_link, $leftover,
          $leftover_name, $leftover_closed);

      $taskv = $tasks;
      $tasks = mgroup($tasks, 'getPriority');

      list($row, $total) = $this->getPriorityMap($name, $tasks);
      list($row, $oldest_all, $oldest_pri) = $this->renderTaskLinks($taskv,
          $closed, $row);

      $row['sort'] = $this->setSortOrder($row, $order, $total, $oldest_all,
          $oldest_pri, $closed, $handle);

      $rows[] = $row;
    }
    $rows = isort($rows, 'sort');

    foreach ($rows as $k => $row) {
      unset($rows[$k]['sort']);
    }

    if ($reverse) {
      $rows = array_reverse($rows);
    }
    return $rows;
  }

  private function renderTaskLinks($taskv, $closed, $row) {
    list($link, $oldest_all) = $this->renderOldest($taskv);
    $row[] = $link;

    $normal_or_better = array();
    foreach ($taskv as $id => $task) {
      // TODO: This is sort of a hard-code for the default "normal" status.
      // When reports are more powerful, this should be made more general.
      if ($task->getPriority() < 50) {
        continue;
      }
      $normal_or_better[$id] = $task;
    }

    list($link, $oldest_pri) = $this->renderOldest($normal_or_better);
    $row[] = $link;

    if ($closed) {
      $task_ids = implode(',', mpull($closed, 'getID'));
      $row[] = phutil_tag(
          'a',
          array(
              'href' => '/maniphest/?ids='.$task_ids,
              'target' => '_blank',
          ),
          number_format(count($closed)));
    } else {
      $row[] = '-';
    }
    return array($row, $oldest_all, $oldest_pri);
  }

  private function getPriorityMap($name, $tasks) {
    $row = array();
    $row[] = $name;
    $total = 0;
    foreach (ManiphestTaskPriority::getTaskPriorityMap() as $pri => $label) {
      $n = count(idx($tasks, $pri, array()));
      if ($n == 0) {
        $row[] = '-';
      } else {
        $row[] = number_format($n);
      }
      $total += $n;
    }
    $row[] = number_format($total);
    return array($row, $total);
  }

  private function setTaskArrays($handle, $project_handle, $result,
                                 $result_closed, $base_link, $leftover,
                                 $leftover_name, $leftover_closed) {
    if ($handle) {
      if (($project_handle) &&
          ($project_handle->getPHID() == $handle->getPHID())) {
        $tasks = idx($result, $handle->getPHID(), array());
        $closed = idx($result_closed, $handle->getPHID(), array());
      } else {
        $tasks = idx($result, $handle->getPHID(), array());
        $closed = idx($result_closed, $handle->getPHID(), array());
      }

      $name = phutil_tag(
          'a',
          array(
              'href' => $base_link.$handle->getPHID(),
          ),
          $handle->getName());

    } else {
      $tasks = $leftover;
      $name  = $leftover_name;
      $closed = $leftover_closed;
    }
    return array($tasks, $name, $closed);
  }

  /**
   * @param string $col_header
   */
  private function buildTableColumns($col_header) {
    $cname = array($col_header);
    $cclass = array('pri left narrow');
    $pri_map = ManiphestTaskPriority::getShortNameMap();
    foreach ($pri_map as $pri => $label) {
      $cname[] = $label;
      $cclass[] = 'center narrow';
    }
    $cname[] = 'Total';
    $cclass[] = 'center narrow';
    $cname[] = javelin_tag(
        'span',
        array(
            'sigil' => 'has-tooltip',
            'meta'  => array(
                'tip' => pht('Oldest open task.'),
                'size' => 200,
            ),
        ),
        pht('Oldest (All)'));
    $cclass[] = 'center narrow';
    $cname[] = javelin_tag(
        'span',
        array(
            'sigil' => 'has-tooltip',
            'meta'  => array(
                'tip' => pht('Oldest open task, excluding those with Low or '.
                    'Wishlist priority.'),
                'size' => 200,
            ),
        ),
        pht('Oldest (Pri)'));
    $cclass[] = 'center narrow';

    list($window_epoch) = $this->getWindow($this->request, $this->viewer);
    $edate = phabricator_datetime($window_epoch, $this->viewer);
    $cname[] = javelin_tag(
        'span',
        array(
            'sigil' => 'has-tooltip',
            'meta'  => array(
                'tip'  => pht('Closed after %s', $edate),
                'size' => 260,
            ),
        ),
        pht('Recently Closed'));
    $cclass[] = 'center narrow';
    return array($cname, $cclass);
  }

  /**
   * @param string[] $cclass
   * @param string $order
   * @param integer $reverse
   */
  private function buildOpenTasksTable($rows, $cname, $cclass, $order,
                                       $reverse) {
    $table = new AphrontTableView($rows);
    $table->setHeaders($cname);
    $table->setColumnClasses($cclass);
    $table->makeSortable(
        $this->request->getRequestURI(),
        'order',
        $order,
        $reverse,
        array(
            'name',
            null,
            null,
            null,
            null,
            null,
            null,
            'total',
            'oldest-all',
            'oldest-pri',
            'closed',
        ));
    return $table;
  }

  /**
   * Load all the tasks that have been recently closed.
   */
  private function loadRecentlyClosedTasks() {
    list(, , $window_epoch) = $this->getWindow($this->request, $this->viewer);

    $table = new ManiphestTask();
    $xtable = new ManiphestTransaction();
    $conn_r = $table->establishConnection('r');

    // TODO: Gross. This table is not meant to be queried like this. Build
    // real stats tables.

    $rows = queryfx_all(
        $conn_r,
        'SELECT t.id FROM %T t JOIN %T x ON x.objectPHID = t.phid
        WHERE t.status NOT IN (%Ls)
        AND x.oldValue IN (null, %Ls)
        AND x.newValue NOT IN (%Ls)
        AND t.dateModified >= %d
        AND x.dateCreated >= %d',
        $table->getTableName(),
        $xtable->getTableName(),
        ManiphestTaskStatus::getOpenStatusConstants(),
        $this->getOpenStatusList(),
        $this->getOpenStatusList(),
        $window_epoch,
        $window_epoch);

    if (!$rows) {
      return array();
    }

    $ids = ipull($rows, 'id');

    return id(new ManiphestTaskQuery())
        ->setViewer($this->request->getUser())
        ->withIDs($ids)
        ->needProjectPHIDs(true)
        ->execute();
  }

  /**
   * @param PhabricatorUser $viewer
   */
  private function getOpenTasks($viewer) {
    $query = $this->openStatusQuery($viewer);
    $tasks = $query->execute();
    return $tasks;
  }

  /**
   * @param PhabricatorUser $viewer
   */
  private function getOpenTasksforProject($viewer, $phids) {
    $query = id(new ManiphestTaskQuery())
        ->setViewer($viewer)
        ->needProjectPHIDs(true)
        ->withEdgeLogicPHIDs(
            PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
            PhabricatorQueryConstraint::OPERATOR_OR,
            $phids)
        ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants());
    $tasks = $query->execute();
    return $tasks;
  }

  private function openStatusQuery($viewer) {
    $query = id(new ManiphestTaskQuery())
        ->setViewer($viewer)
        ->needProjectPHIDs(true)
        ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants());
    return $query;
  }

  private function renderOldest(array $tasks) {
    assert_instances_of($tasks, 'ManiphestTask');
    $oldest = null;
    foreach ($tasks as $id => $task) {
      if (($oldest === null) ||
          ($task->getDateCreated() < $tasks[$oldest]->getDateCreated())) {
        $oldest = $id;
      }
    }

    if ($oldest === null) {
      return array('-', 0);
    }

    $oldest = $tasks[$oldest];

    $raw_age = (time() - $oldest->getDateCreated());
    $age = number_format($raw_age / (24 * 60 * 60)).' d';

    $link = javelin_tag(
        'a',
        array(
            'href'  => '/T'.$oldest->getID(),
            'sigil' => 'has-tooltip',
            'meta'  => array(
                'tip' => 'T'.$oldest->getID().': '.$oldest->getTitle(),
            ),
            'target' => '_blank',
        ),
        $age);

    return array($link, $raw_age);
  }
}
