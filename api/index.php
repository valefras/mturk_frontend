<?php

/*

# nginx config
location / {
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' '*';
        add_header 'Access-Control-Allow-Methods' "POST,GET,DELETE,PUT,OPTIONS";
        add_header 'Access-Control-Allow-Headers' 'DNT,X-Mx-ReqToken,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type';
        add_header 'Access-Control-Max-Age' 1728000;
        return 204;
    }
}

*/

// header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
// header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,DELETE");
// header("Access-Control-Max-Age: 3600");
// header("Access-Control-Allow-Headers: Accept, Referer, User-Agent, Content-Type, Origin, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// ini_set("display_errors", "On");

// require_once 'vendor/autoload.php';
// print_r($_REQUEST);

$script_uri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}";

session_start();

require_once("config.php");
require_once("inc/include.php");
require_once("inc/Mysql_connector.class.php");
require_once("vendor/autoload.php");

$Action = isset($_REQUEST['action']) ? $_REQUEST['action'] : "";
$User = 1;

$DB = new Mysql_connector($DB_HOST, $DB_USERNAME, $DB_PASSWORD);
$DB->select_db($DB_NAME);

$mysqli = $DB->connection;

$_SESSION['Options'] = loadOptions();
if (!isset($_SESSION['Options'])) {
    $_SESSION['Options'] = loadOptions();
}
$Options = $_SESSION['Options'];

// require_once("update.php");

$ret = array();
$ret['debug'] = array();

$json_params = file_get_contents("php://input");
if (strlen($json_params) > 0 && isValidJSON($json_params)) {
    $values = json_decode($json_params, true);
    // $ret['values'] = print_r($values, true);
    $_POST = $values;
    foreach ($_POST as $key => $value) {
        $_REQUEST[$key] = $value;
    }
}

$ret['debug']['request'] = $_REQUEST;

$Delimiters = array("tab" => '\t', "comma" => ',', "semicolon" => ';');
$Enclosures = array("single" => '\'', "double" => '\"', "none" => chr(8));

// $ret['comments'] = $json_params;
// $ret['post'] = print_r($_POST, true);

$UserInfo = find("users", $User, "User not found");
$UseSandbox = true;
$mTurkOptions = [
    'version' => 'latest',
    'region'  => $UserInfo['region_name'],
    'credentials' => [
        'key' => $UserInfo['aws_access_key_id'],
        'secret' => $UserInfo['aws_secret_access_key'],
    ]
];
if ($UseSandbox) {
    $mTurkOptions['endpoint'] = 'https://mturk-requester-sandbox.us-east-1.amazonaws.com';
}
$mTurk = new Aws\MTurk\MTurkClient($mTurkOptions);

switch ($Action) {
    case "listProjects":
    case "addProject":
    case "deleteProject":
    case "editProject":
    case "uploadFile":
    case "deleteFile":
    case "getData":
    case "getUserInfo":
    case "getProjectInfo":
    case "updateProjectStatus":

        // if (!$_SESSION['Admin']) {
        //     $ret['result'] = "ERR";
        //     $ret['error'] = $Lang["not_logged"];
        //     break;
        // }

        switch ($Action) {

            case "getUserInfo":
                $ret['data'] = array();
                $balance = $mTurk->getAccountBalance()->get('AvailableBalance');
                $ret['data']['balance'] = $balance;
                $ret['data']['username'] = $UserInfo['username'];
                $ret['data']['common_name'] = $UserInfo['common_name'];
                $ret['data']['use_sandbox'] = $UseSandbox;
                $ret['result'] = "OK";
                break;

            case "getData":
                $isGold = boolval($_REQUEST['isGold']);
                $project = find("projects", $_REQUEST['id'], "Project not found");
                $isGold = $isGold ? 1 : 0;
                $howMany = $_REQUEST['howMany'] ? $_REQUEST['howMany'] : 50;
                $page = $_REQUEST['page'] ? $_REQUEST['page'] : 1;

                if (!is_numeric($howMany)) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "howMany parameter must be numeric.";
                    break;
                }

                if (!is_numeric($page)) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "page parameter must be numeric.";
                    break;
                }

                $offset = $howMany * ($page - 1);

                $query = "SELECT * FROM project_files
                    WHERE project_id = '{$project['id']}' AND deleted = 0 AND is_gold = '$isGold'";
                if (!$DB->querynum($query)) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "File not found.";
                    break;
                }
                $r = $DB->fetch();

                $query = "SELECT line_text FROM file_lines
                    WHERE file_id = '{$r['id']}'";
                if (!$num = $DB->querynum($query)) {
                    $ret['result'] = "ERR";
                    $ret['error'] = $DB->get_error();
                    break;
                }

                $query = "SELECT l.line_text, GROUP_CONCAT(c.cluster_index) cluster_index
                    FROM file_lines l
                    LEFT JOIN clusters c ON c.line_id = l.id
                    LEFT JOIN project_files f ON f.id = l.file_id
                    WHERE f.deleted = '0' AND f.project_id = '{$project['id']}' AND f.is_gold = '$isGold'
                    GROUP BY l.id
                    LIMIT $offset, $howMany";

                // $query = "SELECT line_text FROM file_lines
                //     WHERE file_id = '{$r['id']}'
                //     ORDER BY id
                //     LIMIT $offset, $howMany";
                $DB->query($query);

                $data = array();
                $cluster_indexes = array();
                while ($row = $DB->fetch()) {
                    $data[] = unserialize($row['line_text']);
                    $cluster_indexes[] = $row['cluster_index'];
                }
                $ret['result'] = "OK";
                $ret['data'] = $data;
                $ret['cluster_indexes'] = $cluster_indexes;
                $ret['num'] = $num;
                $ret['filename'] = $r['filename'];
                $ret['fields'] = unserialize($r['fields']);

                break;

            case "deleteFile":
                $isGold = boolval($_REQUEST['isGold']);
                $project = find("projects", $_REQUEST['id'], "Project not found");
                $isGold = $isGold ? 1 : 0;
                if ($stmt = $mysqli->prepare("UPDATE project_files SET deleted=1 WHERE project_id=? AND is_gold=?")) {
                    $stmt->bind_param("si", $_REQUEST['id'], $isGold);
                    $stmt->execute();
                    $stmt->close();
                }
                $ret['result'] = "OK";
                break;

            case "uploadFile":
                $r = find("projects", $_REQUEST['id'], "Project not found");

                $delimiter = @$_REQUEST['char'];
                if (!$delimiter) {
                    $delimiter = "comma";
                }
                if (!isset($Delimiters[$delimiter])) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "Invalid delimiter.";
                    break;
                }

                $enclosure = @$_REQUEST['enclosure'];
                if (!$enclosure) {
                    $enclosure = "double";
                }
                if (!isset($Enclosures[$enclosure])) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "Invalid enclosure.";
                    break;
                }

                $fieldsTitlesInFirstLine = !! @$_REQUEST['fieldsTitlesInFirstLine'];
                $isGold = boolval($_REQUEST['isGold']);
                $isGold = $isGold ? 1 : 0;
                $fieldTitles = @$_REQUEST['fieldTitles'];
                $fileName = @$_FILES['csvFile']['name'];
                $type = @$_FILES['csvFile']['type'];
                $project = find("projects", $_REQUEST['id'], "Project not found");

                if (!$fileName) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "Invalid file.";
                    break;
                }

                $fields = array();
                $fieldCount = -1;
                if (!$fieldsTitlesInFirstLine) {
                    $fields = explode(',', $fieldsTitles);
                    $fields = array_map('trim', $fields);
                    $fieldCount = count($fields);
                }

                $delimiter = $Delimiters[$delimiter];
                $enclosure = $Enclosures[$enclosure];

                // if ($type != "text/csv") {
                //     $ret['result'] = "ERR";
                //     $ret['error'] = "Invalid type ($type). Should be text/csv.";
                //     break;
                // }

                $okData = array();
                $line = 0;
                if (($handle = fopen($_FILES['csvFile']['tmp_name'], "r")) !== FALSE) {
                    while (($data = fgetcsv($handle, 0, $delimiter, $enclosure)) !== FALSE) {
                        $line++;
                        if ($fieldCount >= 0 && count($data) != $fieldCount) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Wrong field number on line $line.";
                            break 2;
                        }
                        if ($fieldCount == -1) {
                            $fieldCount = count($data);
                        }
                        $okData[] = $data;
                    }
                    fclose($handle);
                }

                if ($fieldsTitlesInFirstLine) {
                    $fields = array_shift($okData);
                }

                if ($isGold) {
                    $query = "SELECT * FROM project_files WHERE project_id = '{$r['id']}' AND deleted = '0' AND is_gold = '0'";
                    if (!$DB->querynum($query)) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "You must insert training file before gold file.";
                        break;
                    }
                    $rT = $DB->fetch();
                    $fieldsT = unserialize($rT['fields']);
                    if (count(array_intersect($fieldsT, $fields)) != count($fieldsT)) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "Fields in the training file are not included in the gold file.";
                        break;
                    }
                    $diff = array_diff($fields, $fieldsT);
                    if (!count($diff)) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "The gold file must contain at least one field more than the training file.";
                        break;
                    }
                }

                $DB->startTransaction();
                $success = true;

                if ($stmt = $mysqli->prepare("UPDATE project_files
                        SET deleted = 1
                        WHERE project_id = ? AND is_gold = ?")) {
                    $stmt->bind_param("si", $_REQUEST['id'], $isGold);
                    if (!$stmt->execute()) {
                        $success = false;
                    }
                    $stmt->close();
                }

                $data = array();
                $data['project_id'] = $project['id'];
                $data['filename'] = $fileName;
                $data['is_gold'] = $isGold;
                $data['fields'] = serialize($fields);
                if (!$DB->queryinsert("project_files", $data)) {
                    $success = false;
                }
                $file_id = $DB->last_id;

                foreach ($okData as $row) {
                    $data = array();
                    $data['file_id'] = $file_id;
                    $data['line_text'] = serialize($row);
                    if (!$DB->queryinsert("file_lines", $data)) {
                        $success = false;
                    }
                }

                if (!$success) {
                    $DB->rollbackTransaction();
                    $ret['result'] = "ERR";
                    $ret['error'] = $DB->get_error();
                    break;
                }

                $DB->commitTransaction();
                $ret['result'] = "OK";
                break;

            case "listProjects":
                $query = "SELECT p.*,
                        (SELECT COUNT(fl1.id) FROM file_lines fl1
                         LEFT JOIN project_files pf1 ON pf1.id = fl1.file_id
                         WHERE pf1.is_gold = '0' AND pf1.deleted = '0' AND pf1.project_id = p.id) count_train,
                        (SELECT COUNT(fl2.id) FROM file_lines fl2
                         LEFT JOIN project_files pf2 ON pf2.id = fl2.file_id
                         WHERE pf2.is_gold = '1' AND pf2.deleted = '0' AND pf2.project_id = p.id) count_gold
                    FROM projects p
                    WHERE p.deleted = 0 AND p.user_id = '1' AND user_id = '{$UserInfo['id']}'";
                // $query = "SELECT * FROM projects
                //     WHERE deleted = 0 AND user_id = '{$UserInfo['id']}'";
                $res = $mysqli->query($query);
                $ret['result'] = "OK";
                $ret['values'] = $res->fetch_all(MYSQLI_ASSOC);
                break;

            case "deleteProject":
                $r = find("projects", $_REQUEST['id'], "Project not found");
                if ($stmt = $mysqli->prepare("UPDATE projects SET deleted = 1 WHERE id = ?")) {
                    $stmt->bind_param("s", $_REQUEST['id']);
                    $stmt->execute();
                    $stmt->close();
                }
                $ret['result'] = "OK";
                break;

            case "updateProjectStatus":
                $r = find("projects", $_REQUEST['id'], "Project not found");

                $toStatus = $_REQUEST['toStatus'];
                $params = $r['params'];

                switch ($toStatus) {
                    case "0":
                        if ($r['status'] != 1) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Cannot proceed to status 0 for a project with status {$r['status']}";
                            break;
                        }

                        $query = "UPDATE `clusters` c
                            LEFT JOIN file_lines l ON l.id = c.line_id
                            LEFT JOIN project_files f ON f.id = l.file_id
                            SET c.deleted = 1
                            WHERE c.deleted = 0 AND f.project_id = '{$r['id']}'";
                        $DB->query($query);

                        $DB->queryupdate("projects", array("status" => 0), array("id" => $r['id']));
                        $ret['result'] = "OK";

                        break;

                    case "1":

                        $goldSize = $_REQUEST['goldSize'];
                        $deleteExceedingValues = boolval($_REQUEST['deleteExceedingValues']);
                        $shuffleGold = boolval($_REQUEST['shuffleGold']);
                        $shuffleData = boolval($_REQUEST['shuffleData']);

                        if ($r['status'] != 0 && $r['status'] != 2) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Cannot proceed to status 1 for a project with status {$r['status']}";
                            break;
                        }

                        if ($r['status'] == 2) {
                            $query = "UPDATE projects SET hit_details = NULL, status = 1 WHERE id = '{$r['id']}'";
                            $DB->query($query);
                            $ret['result'] = "OK";
                            break;
                        }

                        if (!preg_match("/[0-9]+/", $goldSize)) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Invalid gold size";
                            break;
                        }
                        if ($goldSize >= $params) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Gold size must be less than the number of params";
                            break;
                        }

                        $dataBunch = $params - $goldSize;

                        $goldData = array();
                        $trainData = array();
                        $query = "SELECT l.line_text, f.is_gold, l.id
                            FROM file_lines l
                            LEFT JOIN project_files f ON l.file_id = f.id
                            WHERE f.project_id = '{$r['id']}' AND f.deleted = '0'
                            ORDER BY l.id";
                        $DB->query($query);
                        while ($row = $DB->fetch()) {
                            if ($row['is_gold']) {
                                $goldData[] = $row;
                            }
                            else {
                                $trainData[] = $row;
                            }
                        }

                        if (!count($trainData)) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Project has no data";
                            break;
                        }
                        if (!count($goldData) && $goldSize > 0) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Project has no gold data, but gold data is needed for the task";
                            break;
                        }

                        if ($shuffleData) {
                            shuffle($trainData);
                        }
                        if ($shuffleGold) {
                            shuffle($goldData);
                        }

                        $remain = count($trainData) % $dataBunch;
                        if ($deleteExceedingValues) {
                            for ($i = 0; $i < $remain; $i++) {
                                array_pop($trainData);
                            }
                        }
                        else {
                            for ($i = 0; $i < $dataBunch - $remain; $i++) {
                                $trainData[] = $trainData[$i];
                            }
                        }

                        $DB->startTransaction();
                        $success = true;

                        $goldIndex = 0;
                        for ($i = 0; $i < count($trainData); $i += $dataBunch) {
                            $clusterIndex = floor($i / 7) + 1;
                            for ($j = 0; $j < $dataBunch; $j++) {
                                $index = $i + $j;
                                $data = array();
                                $data['cluster_index'] = $clusterIndex;
                                $data['line_id'] = $trainData[$index]['id'];
                                if (!$DB->queryinsert("clusters", $data)) {
                                    $success = false;
                                }

                                // print("T{$trainData[$index]['id']}\n");
                            }
                            for ($j = 0; $j < $goldSize; $j++) {
                                $index = $goldIndex++ % count($goldData);
                                $data = array();
                                $data['cluster_index'] = $clusterIndex;
                                $data['line_id'] = $goldData[$index]['id'];
                                if (!$DB->queryinsert("clusters", $data)) {
                                    $success = false;
                                }

                                // print("G{$goldData[$index]['id']}\n");
                            }
                        }

                        if (!$DB->queryupdate("projects", array("status" => 1), array("id" => $r['id']))) {
                            $success = false;
                        }
                        if (!$success) {
                            $DB->rollbackTransaction();
                            $ret['result'] = "ERR";
                            $ret['error'] = $DB->get_error();
                            break;
                        }

                        $DB->commitTransaction();
                        $ret['result'] = "OK";

                        break;

                    case "2":
                        if ($r['status'] != 1) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "Cannot proceed to status 2 for a project with status {$r['status']}";
                            break;
                        }

                        if (!count($_REQUEST['layoutData'])) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "No layoutData found";
                            break;
                        }

                        $goldInfo = false;
                        $trainingInfo = false;
                        $query = "SELECT * FROM project_files WHERE project_id = '{$r['id']}' AND deleted = '0'";
                        $DB->query($query);
                        while ($row = $DB->fetch()) {
                            if ($row['is_gold']) {
                                $goldInfo = $row;
                            }
                            else {
                                $trainingInfo = $row;
                            }
                        }

                        $allowedGoldWrong = array();
                        $allowedGoldWrong[] = "accept";
                        $allowedGoldWrong[] = "reject";
                        $allowedGoldWrong[] = "wait";

                        $allowedFields = array();
                        $allowedFields[] = "_name";
                        $allowedFields[] = "_title";
                        $allowedFields[] = "_description";
                        $allowedFields[] = "_keywords";
                        $allowedFields = array_merge($allowedFields, unserialize($trainingInfo['fields']));

                        $layout_fields = explode("\n", $r['layout_fields']);
                        $layout_fields = array_map('trim', $layout_fields);

                        if (!count($_REQUEST['layoutData'])) {
                            $ret['result'] = "ERR";
                            $ret['error'] = "No layout data specified";
                            break;
                        }

                        foreach ($_REQUEST['layoutData'] as $layoutData) {
                            if (!in_array($layoutData['field'], $layout_fields)) {
                                $ret['result'] = "ERR";
                                $ret['error'] = "Unable to find {$layoutData['field']} in layout fields";
                                break 2;
                            }
                            if ($layoutData['handwritten']) {
                                if (!$layoutData['customValue']) {
                                    $ret['result'] = "ERR";
                                    $ret['error'] = "Custom value is mandatory for handwritten fields";
                                    break 2;
                                }
                            }
                            else {
                                if (!in_array($layoutData['valueFrom'], $allowedFields)) {
                                    $ret['result'] = "ERR";
                                    $ret['error'] = "Invalid field name {$layoutData['valueFrom']}";
                                    break 2;
                                }
                            }
                        }

                        if ($goldInfo) {
                            if (!count($_REQUEST['answerData'])) {
                                $ret['result'] = "ERR";
                                $ret['error'] = "No layout data specified";
                                break;
                            }

                            foreach ($_REQUEST['answerData'] as $answerData) {
                                foreach (["varName", "varValue", "varNameTo", "varValueTo"] as $key) {
                                    if (!isset($answerData[$key]) || !isset($answerData[$key])) {
                                        $ret['result'] = "ERR";
                                        $ret['error'] = "Field $key is not defined";
                                        break 3;
                                    }
                                }
                            }

                            if (!in_array($_REQUEST['whatToDo'], $allowedGoldWrong)) {
                                $ret['result'] = "ERR";
                                $ret['error'] = "Invalid value {$_REQUEST['whatToDo']} for whatToDo";
                                break;
                            }

                            if (!preg_match("/[0-9]+/", $_REQUEST['assignNumber'])) {
                                $ret['result'] = "ERR";
                                $ret['error'] = "Invalid value {$_REQUEST['assignNumber']} for assignNumber";
                                break;
                            }
                            if ($_REQUEST['assignNumber'] < $r['workers']) {
                                $ret['result'] = "ERR";
                                $ret['error'] = "assignNumber must be more than {$r['workers']} in this project";
                                break;
                            }
                        }

                        $toSave = array();
                        $toSave['layoutData'] = $_REQUEST['layoutData'];
                        $toSave['answerData'] = $_REQUEST['answerData'];
                        $toSave['assignNumber'] = $_REQUEST['assignNumber'];
                        $toSave['whatToDo'] = $_REQUEST['whatToDo'];

                        $DB->queryupdate("projects", array("hit_details" => $toSave, "status" => 2), array("id" => $r['id']));

                        break;

                    default:
                        $ret['result'] = "ERR";
                        $ret['error'] = "Unknown status";
                }
                break;

            case "getProjectInfo":
                $r = find("projects", $_REQUEST['id'], "Project not found");

                $ret['issues'] = array();
                $numData = false;
                $numGold = false;
                $query = "SELECT * FROM file_lines l
                    LEFT JOIN project_files f ON l.file_id = f.id
                    WHERE f.project_id = '{$r['id']}' AND f.deleted = '0' AND f.is_gold = '0'";
                $numData = $DB->querynum($query);
                if (!$numData) {
                    $ret['issues'][] = "Training file missing";
                }
                $query = "SELECT * FROM file_lines l
                    LEFT JOIN project_files f ON l.file_id = f.id
                    WHERE f.project_id = '{$r['id']}' AND f.deleted = '0' AND f.is_gold = '1'";
                $numGold = $DB->querynum($query);

                $ret['goldFields'] = array();
                $ret['dataFields'] = array();
                $query = "SELECT * FROM project_files WHERE project_id = '{$r['id']}' AND deleted = '0'";
                $DB->query($query);
                while ($row = $DB->fetch()) {
                    if ($row['is_gold']) {
                        $ret['goldFields'] = unserialize($row['fields']);
                    }
                    else {
                        $ret['dataFields'] = unserialize($row['fields']);
                    }
                }
                $ret['goldFields'] = array_diff($ret['goldFields'], $ret['dataFields']);

                $ret['numGold'] = $numGold;
                $ret['numData'] = $numData;

                $ret['result'] = "OK";
                $ret['values'] = $r;
                break;

            // Deprecated
            case "editProject":
                $r = find("projects", $_REQUEST['id'], "Project not found");
                $ret['result'] = "OK";
                $ret['values'] = $r;
                break;

            case "addProject":
                $fields = array("name", "title", "description", "keywords", "reward",
                    "workers", "max_time", "expiry", "auto_approve", "layout_id", "params",
                    "params_fields");
                $integers = array("workers", "max_time", "params", "auto_approve", "expiry");

                $r = NULL;
                if ($_REQUEST['id'] != 0) {
                    $r = find("projects", $_REQUEST['id'], "Project not found");
                    if ($r['status'] > 1) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "A project with status {$ret['status']} cannot be modified";
                        break;
                    }
                    if ($r['status'] == 1) {
                        $fields.remove("params");
                        $integers.remove("params");
                    }
                }

                // Important to avoid that malicious $_REQUEST indexes
                // are added to the SQL insert statement
                $data = array();
                foreach ($fields as $field) {
                    $data[$field] = $_REQUEST[$field];
                }

                foreach ($fields as $field) {
                    if (!trim($data[$field])) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "Field '$field' is mandatory";
                        break 2;
                    }
                }

                if (!is_numeric($data['reward'])) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "Field 'reward' must be numeric";
                    break;
                }
                foreach ($integers as $field) {
                    if (!preg_match("/^[0-9]+$/", $data[$field])) {
                        $ret['result'] = "ERR";
                        $ret['error'] = "Field '$field' must be integer";
                        break 2;
                    }
                }
                if ($data['params'] < 1) {
                    $ret['result'] = "ERR";
                    $ret['error'] = "Invalid number of params";
                    break;
                }

                // A fake HIT is created to get information about the layout
                try {
                    $result = $mTurk->createHIT([
                        "MaxAssignments" => 3,
                        "LifetimeInSeconds" => 0,
                        "Reward" => "0",
                        "Title" => "Title",
                        "Description" => "Description",
                        "HITLayoutId" => $data['layout_id'],
                        "AssignmentDurationInSeconds" => 30
                    ]);
                } catch (Exception $e) {
                    $msg = $e->getMessage();
                    $ret['debug']['layout_result'] = $msg;
                    if (preg_match("/Missing parameter names: ([^.]*)\./", $msg, $matches)) {
                        $data['layout_fields'] = str_replace(",", ", ", $matches[1]);
                    }
                    else {
                        $ret['result'] = "ERR";
                        $ret['error'] = "Invalid layout ID";
                        break;
                    }
                }

                $layout_fields = explode(",", $data['layout_fields']);
                $layout_fields = array_map("trim", $layout_fields);
                $params_fields = explode(",", $data['params_fields']);
                $params_fields = array_map("trim", $params_fields);

                $all_included = true;
                foreach ($params_fields as $param_field) {
                    for ($i = 1; $i <= $data['params']; $i++) {
                        $p = $param_field . $i;
                        $found_key = array_search($p, $layout_fields);
                        if ($found_key === false) {
                            $all_included = false;
                            $ret['result'] = "ERR";
                            $ret['error'] = "$p is not included in layout";
                            break 3;
                        }
                        array_splice($layout_fields, $found_key, 1);
                    }
                    $layout_fields[] = $param_field . "#";
                }

                $data['layout_fields'] = implode(', ', $layout_fields);
                $data['params_fields'] = implode(', ', $params_fields);

                // $ret['layout_fields'] = $layout_fields;

                // $ret['data'] = $data;

                if ($r === NULL) {
                    $DB->queryinsert("projects", $data);
                    $ret['result'] = "OK";
                    break;
                }
                else {
                    $where = array("id" => $_REQUEST['id']);
                    $DB->queryupdate("projects", $data, $where);
                    $ret['result'] = "OK";
                    break;
                }

                break;
        }
        
        break;

}

echo json_encode($ret, JSON_PRETTY_PRINT);