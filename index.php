<?php

/********************************
Simple PHP File Manager
Copyright John Campbell (jcampbell1)
Liscense: MIT
 ********************************/

//Disable error report for undefined superglobals
error_reporting(error_reporting() & ~E_NOTICE);

//Security options
$allow_delete = true; // Set to false to disable delete button and delete POST request.
$allow_upload = true; // Set to true to allow upload files
$allow_create_folder = true; // Set to false to disable folder creation
$allow_direct_link = true; // Set to false to only allow downloads and not direct link
$allow_show_folders = true; // Set to false to hide all subdirectories

$disallowed_patterns = ['*.php'];  // must be an array.  Matching files not allowed to be uploaded
$hidden_patterns = ['*.php', '.*']; // Matching files hidden in directory index

$PASSWORD = '';  // Set the password, to access the file manager... (optional)


/* niyamat custom work */

$tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();



/* niyamat custom work finish here */

if ($PASSWORD) {

	session_start();
	if (!$_SESSION['_sfm_allowed']) {
		// sha1, and random bytes to thwart timing attacks.  Not meant as secure hashing.
		$t = bin2hex(openssl_random_pseudo_bytes(10));
		if ($_POST['p'] && sha1($t . $_POST['p']) === sha1($t . $PASSWORD)) {
			$_SESSION['_sfm_allowed'] = true;
			header('Location: ?');
		}
		echo '<html><body><form action=? method=post>PASSWORD:<input type=password name=p autofocus/></form></body></html>';
		exit;
	}
}

// must be in UTF-8 or `basename` doesn't work
setlocale(LC_ALL, 'en_US.UTF-8');

$tmp_dir = dirname($_SERVER['SCRIPT_FILENAME']);
if (DIRECTORY_SEPARATOR === '\\') $tmp_dir = str_replace('/', DIRECTORY_SEPARATOR, $tmp_dir);
$tmp = get_absolute_path($tmp_dir . '/' . $_REQUEST['file']);

if ($tmp === false)
	err(404, 'File or Directory Not Found');
if (substr($tmp, 0, strlen($tmp_dir)) !== $tmp_dir)
	err(403, "Forbidden");
if (strpos($_REQUEST['file'], DIRECTORY_SEPARATOR) === 0)
	err(403, "Forbidden");
if (preg_match('@^.+://@', $_REQUEST['file'])) {
	err(403, "Forbidden");
}


if (!$_COOKIE['_sfm_xsrf'])
	setcookie('_sfm_xsrf', bin2hex(openssl_random_pseudo_bytes(16)));
if ($_POST) {
	if ($_COOKIE['_sfm_xsrf'] !== $_POST['xsrf'] || !$_POST['xsrf'])
		err(403, "XSRF Failure");
}

$file = $_REQUEST['file'] ?: '.';

if ($_GET['do'] == 'list') {
	if (is_dir($file)) {
		$directory = $file;
		$result = [];
		$files = array_diff(scandir($directory), ['.', '..']);
		foreach ($files as $entry) if (!is_entry_ignored($entry, $allow_show_folders, $hidden_patterns)) {
			$i = $directory . '/' . $entry;
			$stat = stat($i);
			$result[] = [
				'mtime' => $stat['mtime'],
				'size' => $stat['size'],
				'name' => basename($i),
				'path' => preg_replace('@^\./@', '', $i),
				'is_dir' => is_dir($i),
				'is_deleteable' => $allow_delete && ((!is_dir($i) && is_writable($directory)) ||
					(is_dir($i) && is_writable($directory) && is_recursively_deleteable($i))),
				'is_readable' => is_readable($i),
				'is_writable' => is_writable($i),
				'is_executable' => is_executable($i),
			];
		}
		usort($result, function ($f1, $f2) {
			$f1_key = ($f1['is_dir'] ?: 2) . $f1['name'];
			$f2_key = ($f2['is_dir'] ?: 2) . $f2['name'];
			return $f1_key > $f2_key;
		});
	} else {
		err(412, "Not a Directory");
	}
	echo json_encode(['success' => true, 'is_writable' => is_writable($file), 'results' => $result]);
	exit;
} elseif ($_POST['do'] == 'delete') {
	if ($allow_delete) {
		rmrf($file);
	}
	exit;
} elseif ($_POST['do'] == 'mkdir' && $allow_create_folder) {
	// don't allow actions outside root. we also filter out slashes to catch args like './../outside'
	$dir = $_POST['name'];
	$dir = str_replace('/', '', $dir);
	if (substr($dir, 0, 2) === '..')
		exit;
	chdir($file);
	@mkdir($_POST['name']);
	exit;
} elseif ($_POST['do'] == 'upload' && $allow_upload) {
	foreach ($disallowed_patterns as $pattern)
		if (fnmatch($pattern, $_FILES['file_data']['name']))
			err(403, "Files of this type are not allowed.");

	$res = move_uploaded_file($_FILES['file_data']['tmp_name'], $file . '/' . $_FILES['file_data']['name']);
	exit;
} elseif ($_GET['do'] == 'download') {
	foreach ($disallowed_patterns as $pattern)
		if (fnmatch($pattern, $file))
			err(403, "Files of this type are not allowed.");

	$filename = basename($file);
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	header('Content-Type: ' . finfo_file($finfo, $file));
	header('Content-Length: ' . filesize($file));
	header(sprintf(
		'Content-Disposition: attachment; filename=%s',
		strpos('MSIE', $_SERVER['HTTP_REFERER']) ? rawurlencode($filename) : "\"$filename\""
	));
	ob_flush();
	readfile($file);
	exit;
}

function is_entry_ignored($entry, $allow_show_folders, $hidden_patterns)
{
	if ($entry === basename('a')) {
		return true;
	}

	if (is_dir($entry) && !$allow_show_folders) {
		return true;
	}
	foreach ($hidden_patterns as $pattern) {
		if (fnmatch($pattern, $entry)) {
			return true;
		}
	}
	return false;
}

function rmrf($dir)
{
	if (is_dir($dir)) {
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file)
			rmrf("$dir/$file");
		rmdir($dir);
	} else {
		unlink($dir);
	}
}
function is_recursively_deleteable($d)
{
	$stack = [$d];
	while ($dir = array_pop($stack)) {
		if (!is_readable($dir) || !is_writable($dir))
			return false;
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) if (is_dir($file)) {
			$stack[] = "$dir/$file";
		}
	}
	return true;
}

// from: http://php.net/manual/en/function.realpath.php#84012
function get_absolute_path($path)
{
	$path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
	$parts = explode(DIRECTORY_SEPARATOR, $path);
	$absolutes = [];
	foreach ($parts as $part) {
		if ('.' == $part) continue;
		if ('..' == $part) {
			array_pop($absolutes);
		} else {
			$absolutes[] = $part;
		}
	}
	return implode(DIRECTORY_SEPARATOR, $absolutes);
}

function err($code, $msg)
{
	http_response_code($code);
	header("Content-Type: application/json");
	echo json_encode(['error' => ['code' => intval($code), 'msg' => $msg]]);
	exit;
}

function asBytes($ini_v)
{
	$ini_v = trim($ini_v);
	$s = ['g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10];
	return intval($ini_v) * ($s[strtolower(substr($ini_v, -1))] ?: 1);
}
$MAX_UPLOAD_SIZE = (asBytes(1048576000000));



//count total files and folders
$total_items  = count(glob("/", GLOB_ONLYDIR));
$totalfolders = getcwd() . "/";
$totalfiles = getcwd() . "/*.*";
$countFile = 0;
$files = glob($totalfiles . '*');
$folders = glob($totalfolders . '*');
$countfolders = count($folders);
if ($files != false) {
	$countFile = count($files);
}
// count sub 
if (isset($_GET['dirname']) && $_GET['dirname'] !== '') {
	$total_items  = count(glob("/" . $_GET['dirname'], GLOB_ONLYDIR));
	$totalfolders = $totalfolders . $_GET['dirname'] . '/';
	$totalfiles = $totalfolders . '*.*';
	$countFile = 1;
	$files = glob($totalfiles . '*');
	$folders = glob($totalfolders . '*');
	$countfolders = count($folders) + 1;
	if ($files != false) {
		$countFile = count($files) + 1;
	}
}








//disk space php usage:


class DiskStatus
{

	const RAW_OUTPUT = true;

	private $diskPath;


	function __construct($diskPath)
	{
		$this->diskPath = $diskPath;
	}


	public function totalSpace($rawOutput = false)
	{
		$diskTotalSpace = @disk_total_space($this->diskPath);

		if ($diskTotalSpace === FALSE) {
			throw new Exception('totalSpace(): Invalid disk path.');
		}

		return $rawOutput ? $diskTotalSpace : $this->addUnits($diskTotalSpace);
	}


	public function freeSpace($rawOutput = false)
	{
		$diskFreeSpace = @disk_free_space($this->diskPath);

		if ($diskFreeSpace === FALSE) {
			throw new Exception('freeSpace(): Invalid disk path.');
		}

		return $rawOutput ? $diskFreeSpace : $this->addUnits($diskFreeSpace);
	}


	public function usedSpace($precision = 1)
	{
		try {
			return round((100 - ($this->freeSpace(self::RAW_OUTPUT) / $this->totalSpace(self::RAW_OUTPUT)) * 100), $precision);
		} catch (Exception $e) {
			throw $e;
		}
	}


	public function getDiskPath()
	{
		return $this->diskPath;
	}


	private function addUnits($bytes)
	{
		$units = array('B', 'KB', 'MB', 'GB', 'TB');

		for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
			$bytes /= 1024;
		}

		return round($bytes, 1) . ' ' . $units[$i];
	}
}

try {
	$diskStatus = new DiskStatus('/');

	$freeSpace = $diskStatus->freeSpace();
	$totalSpace = $diskStatus->totalSpace();
	$barWidth = ($diskStatus->usedSpace() / 100) * 400;
} catch (Exception $e) {
	echo 'Error (' . $e->getMessage() . ')';
	exit();
}

if (isset($_GET["name"], $_GET["path"], $_GET["new"])) {
	$name = $_GET["name"];
	$full_path = $_GET["path"];
	$new = $_GET["new"];
	$path = explode("/", $full_path);
	if (count($path) > 1) {
		unset($path[count($path) - 1]);
		$path = implode("/", $path) . '/';
	} else {
		$path = '';
	}
	if (file_exists($full_path)) {
		rename($full_path, $path . $new);
	}
}


$maxUpload      = (int)(ini_get('upload_max_filesize'));
$maxPost        = (int)(ini_get('post_max_size'));
$maxexecutetime = (int)(ini_get('max_execution_time'));
$memorylimit = (int)(ini_get('memory_limit'));

?>

<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="content-type" content="text/html; charset=utf-8">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

	<style>
		body {
			font-family: "lucida grande", "Segoe UI", Arial, sans-serif;
			font-size: 14px;
			width: 1024;
			padding: 1em;
			margin: 0;
		}

		th {
			font-weight: normal;
			color: #1f75cc;
			background-color: #f0f9ff;
			padding: 0.5em 1em 0.5em 0.2em;
			text-align: left;
			cursor: pointer;
			user-select: none;
		}

		th .indicator {
			margin-left: 6px;
		}

		thead {
			border-top: 1px solid #82cffa;
			border-bottom: 1px solid #96c4ea;
			border-left: 1px solid #e7f2fb;
			border-right: 1px solid #e7f2fb;
		}

		#top {
			height: 52px;
		}

		#mkdir {
			display: inline-block;
			float: right;
			padding-top: 16px;
		}

		label {
			display: block;
			font-size: 11px;
			color: #555;
		}

		#file_drop_target {
			width: 500px;
			padding: 12px 0;
			border: 4px dashed #ccc;
			font-size: 12px;
			color: #ccc;
			text-align: center;
			float: right;
			margin-right: 20px;
		}

		#file_drop_target.drag_over {
			border: 4px dashed #96c4ea;
			color: #96c4ea;
		}

		#upload_progress {
			padding: 4px 0;
		}

		#upload_progress .error {
			color: #a00;
		}

		#upload_progress>div {
			padding: 3px 0;
		}

		.no_write #mkdir,
		.no_write #file_drop_target {
			display: none;
		}

		.progress_track {
			display: inline-block;
			width: 200px;
			height: 10px;
			border: 1px solid #333;
			margin: 0 4px 0 10px;
		}

		.progress {
			background-color: #82cffa;
			height: 10px;
		}

		footer {
			font-size: 11px;
			color: #bbbbc5;
			padding: 4em 0 0;
			text-align: left;
		}

		footer a,
		footer a:visited {
			color: #bbbbc5;
		}

		#breadcrumb {
			padding-top: 34px;
			font-size: 15px;
			color: #aaa;
			display: inline-block;
			float: left;
		}

		#folder_actions {
			width: 50%;
			float: right;
		}

		a,
		a:visited {
			color: #00c;
			text-decoration: none;
		}

		a:hover {
			text-decoration: underline;
		}

		.sort_hide {
			display: none;
		}

		table {
			border-collapse: collapse;
			width: 100%;
		}

		thead {
			max-width: 1024px;
		}

		td {
			padding: 0.2em 1em 0.2em 0.2em;
			border-bottom: 1px solid #def;
			height: 30px;
			font-size: 12px;
			white-space: nowrap;
		}

		td.first {
			font-size: 14px;
			white-space: normal;
		}

		td.empty {
			color: #777;
			font-style: italic;
			text-align: center;
			padding: 3em 0;
		}

		.is_dir .size {
			color: transparent;
			font-size: 0;
		}

		.is_dir .size:before {
			content: "--";
			font-size: 14px;
			color: #333;
		}

		.is_dir .download {
			visibility: hidden;
		}

		a.delete {
			display: inline-block;
			background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAADtSURBVHjajFC7DkFREJy9iXg0t+EHRKJDJSqRuIVaJT7AF+jR+xuNRiJyS8WlRaHWeOU+kBy7eyKhs8lkJrOzZ3OWzMAD15gxYhB+yzAm0ndez+eYMYLngdkIf2vpSYbCfsNkOx07n8kgWa1UpptNII5VR/M56Nyt6Qq33bbhQsHy6aR0WSyEyEmiCG6vR2ffB65X4HCwYC2e9CTjJGGok4/7Hcjl+ImLBWv1uCRDu3peV5eGQ2C5/P1zq4X9dGpXP+LYhmYz4HbDMQgUosWTnmQoKKf0htVKBZvtFsx6S9bm48ktaV3EXwd/CzAAVjt+gHT5me0AAAAASUVORK5CYII=) no-repeat scroll 0 2px;
			color: #d00;
			margin-left: 15px;
			font-size: 11px;
			padding: 0 0 0 13px;
		}

		.name {
			background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAABAklEQVRIie2UMW6DMBSG/4cYkJClIhauwMgx8CnSC9EjJKcwd2HGYmAwEoMREtClEJxYakmcoWq/yX623veebZmWZcFKWZbXyTHeOeeXfWDN69/uzPP8x1mVUmiaBlLKsxACAC6cc2OPd7zYK1EUYRgGZFkG3/fPAE5fIjcCAJimCXEcGxKnAiICERkSIcQmeVoQhiHatoWUEkopJEkCAB/r+t0lHyVN023c9z201qiq6s2ZYA9jDIwx1HW9xZ4+Ihta69cK9vwLvsX6ivYf4FGIyJj/rg5uqwccd2Ar7OUdOL/kPyKY5/mhZJ53/2asgiAIHhLYMARd16EoCozj6EzwCYrrX5dC9FQIAAAAAElFTkSuQmCC) no-repeat scroll 0px 12px;
			padding: 15px 0 10px 40px;
		}

		.is_dir .name {
			background: url(data:image/png;base64,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) no-repeat scroll 0px 10px;
			padding: 15px 0 10px 40px;
		}

		.download {
			background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAB2klEQVR4nJ2ST2sTQRiHn5mdmj92t9XmUJIWJGq9NHrRgxQiCtqbl97FqxgaL34CP0FD8Qv07EHEU0Ew6EXEk6ci8Q9JtcXEkHR3k+zujIdUqMkmiANzmJdnHn7vzCuIWbe291tSkvhz1pr+q1L2bBwrRgvFrcZKKinfP9zI2EoKmm7Azstf3V7fXK2Wc3ujvIqzAhglwRJoS2ImQZMEBjgyoDS4hv8QGHA1WICvp9yelsA7ITBTIkwWhGBZ0Iv+MUF+c/cB8PTHt08snb+AGAACZDj8qIN6bSe/uWsBb2qV24/GBLn8yl0plY9AJ9NKeL5ICyEIQkkiZenF5XwBDAZzWItLIIR6LGfk26VVxzltJ2gFw2a0FmQLZ+bcbo/DPbcd+PrDyRb+GqRipbGlZtX92UvzjmUpEGC0JgpC3M9dL+qGz16XsvcmCgCK2/vPtTNzJ1x2kkZIRBSivh8Z2Q4+VkvZy6O8HHvWyGyITvA1qndNpxfguQNkc2CIzM0xNk5QLedCEZm1VKsf2XrAXMNrA2vVcq4ZJ4DhvCSAeSALXASuLBTW129U6oPrT969AK4Bq0AeWARs4BRgieMUEkgDmeO9ANipzDnH//nFB0KgAxwATaAFeID5DQNatLGdaXOWAAAAAElFTkSuQmCC) no-repeat scroll 0px 5px;
			padding: 4px 0 4px 20px;
		}

		#file_drop_target {
			width: 60%;
			float: left;
			padding: 64px 0;
			border: 4px dashed #ccc;
			font-size: 12px;
			color: #ccc;
			background: #f8fffa;
			text-align: center;
			float: right;
			margin-right: 20px;
			margin-bottom: 20px;
		}

		th {
			font-weight: normal;
			color: #ffffff;
			background-color: #00a63f;
			padding: 0.5em 1em 0.5em 0.2em;
			text-align: left;
			cursor: pointer;
			font-size: 15px;
			user-select: none;
		}

		a.delete {
			display: inline-block;

			color: #fff;
			text-decoration: none;
			margin-left: 15px;
			font-size: 14px;
			text-transform: capitalize;
			padding: 10px 20px 10px 20px;
			border-radius: 3px;
			background: #d4522beb;
			margin-top: 6px;
		}

		.download {
			padding: 4px 0 4px 20px;
			color: #fff;
			text-decoration: none !important;
			margin-left: 15px;
			font-size: 14px;
			text-transform: capitalize;
			padding: 10px 20px 10px 20px;
			border-radius: 3px;
			background: #55ce83;
		}

		.disk {
			border: 2px solid #b3b3b3;
			width: 400px;
			padding: 1px;
			margin-top: 5px;
			border-radius: 3px;
		}

		.used {
			display: block;
			background: yellow;
			text-align: right;
			padding: 0 0 0 0;
		}

		input.new-folder {
			background: #67d7f9;
			padding: 5px 10px 5px 10px;
			color: white;
			border: 2px solid #90bfce;
			border-radius: 3px;
			cursor: pointer;
		}
	</style>
	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
	<script>
		(function($) {
			$.fn.tablesorter = function() {
				var $table = this;
				this.find('th').click(function() {
					var idx = $(this).index();
					var direction = $(this).hasClass('sort_asc');
					$table.tablesortby(idx, direction);
				});
				return this;
			};
			$.fn.tablesortby = function(idx, direction) {
				var $rows = this.find('tbody tr');

				function elementToVal(a) {
					var $a_elem = $(a).find('td:nth-child(' + (idx + 1) + ')');
					var a_val = $a_elem.attr('data-sort') || $a_elem.text();
					return (a_val == parseInt(a_val) ? parseInt(a_val) : a_val);
				}
				$rows.sort(function(a, b) {
					var a_val = elementToVal(a),
						b_val = elementToVal(b);
					return (a_val > b_val ? 1 : (a_val == b_val ? 0 : -1)) * (direction ? 1 : -1);
				})
				this.find('th').removeClass('sort_asc sort_desc');
				$(this).find('thead th:nth-child(' + (idx + 1) + ')').addClass(direction ? 'sort_desc' : 'sort_asc');
				for (var i = 0; i < $rows.length; i++)
					this.append($rows[i]);
				this.settablesortmarkers();
				return this;
			}
			$.fn.retablesort = function() {
				var $e = this.find('thead th.sort_asc, thead th.sort_desc');
				if ($e.length)
					this.tablesortby($e.index(), $e.hasClass('sort_desc'));

				return this;
			}
			$.fn.settablesortmarkers = function() {
				this.find('thead th span.indicator').remove();
				this.find('thead th.sort_asc').append('<span class="indicator">&darr;<span>');
				this.find('thead th.sort_desc').append('<span class="indicator">&uarr;<span>');
				return this;
			}
		})(jQuery);
		$(function() {
			var XSRF = (document.cookie.match('(^|; )_sfm_xsrf=([^;]*)') || 0)[2];
			var MAX_UPLOAD_SIZE = <?php echo $MAX_UPLOAD_SIZE ?>;
			var $tbody = $('#list');
			$(window).on('hashchange', list).trigger('hashchange');
			$('#table').tablesorter();

			$('#table').on('click', '.delete', function(data) {
				$.post("", {
					'do': 'delete',
					file: $(this).attr('data-file'),
					xsrf: XSRF
				}, function(response) {
					list();
				}, 'json');
				return false;
			});

			$('#mkdir').submit(function(e) {
				var hashval = decodeURIComponent(window.location.hash.substr(1)),
					$dir = $(this).find('[name=name]');
				e.preventDefault();
				$dir.val().length && $.post('?', {
					'do': 'mkdir',
					name: $dir.val(),
					xsrf: XSRF,
					file: hashval
				}, function(data) {
					list();
				}, 'json');
				$dir.val('');
				return false;
			});
			<?php if ($allow_upload) : ?>
				// file upload stuff
				$('#file_drop_target').on('dragover', function() {
					$(this).addClass('drag_over');
					return false;
				}).on('dragend', function() {
					$(this).removeClass('drag_over');
					return false;
				}).on('drop', function(e) {
					e.preventDefault();
					var files = e.originalEvent.dataTransfer.files;
					$.each(files, function(k, file) {
						uploadFile(file);
					});
					$(this).removeClass('drag_over');
				});
				$('input[type=file]').change(function(e) {
					e.preventDefault();
					$.each(this.files, function(k, file) {
						uploadFile(file);
					});
				});


				function uploadFile(file) {
					var folder = decodeURIComponent(window.location.hash.substr(1));

					if (file.size > MAX_UPLOAD_SIZE) {
						var $error_row = renderFileSizeErrorRow(file, folder);
						$('#upload_progress').append($error_row);
						window.setTimeout(function() {
							$error_row.fadeOut();
						}, 5000);
						return false;
					}

					var $row = renderFileUploadRow(file, folder);
					$('#upload_progress').append($row);
					var fd = new FormData();
					fd.append('file_data', file);
					fd.append('file', folder);
					fd.append('xsrf', XSRF);
					fd.append('do', 'upload');
					var xhr = new XMLHttpRequest();
					xhr.open('POST', '?');
					xhr.onload = function() {
						$row.remove();
						list();
					};
					xhr.upload.onprogress = function(e) {
						if (e.lengthComputable) {
							$row.find('.progress').css('width', (e.loaded / e.total * 100 | 0) + '%');
						}
					};
					xhr.send(fd);
				}

				function renderFileUploadRow(file, folder) {
					return $row = $('<div/>')
						.append($('<span class="fileuploadname" />').text((folder ? folder + '/' : '') + file.name))
						.append($('<div class="progress_track"><div class="progress"></div></div>'))
						.append($('<span class="size" />').text(formatFileSize(file.size)))
				};

				function renderFileSizeErrorRow(file, folder) {
					return $row = $('<div class="error" />')
						.append($('<span class="fileuploadname" />').text('Error: ' + (folder ? folder + '/' : '') + file.name))
						.append($('<span/>').html(' file size - <b>' + formatFileSize(file.size) + '</b>' +
							' exceeds max upload size of <b>' + formatFileSize(MAX_UPLOAD_SIZE) + '</b>'));
				}
			<?php endif; ?>

			function list() {
				var hashval = window.location.hash.substr(1);
				$.get('?do=list&file=' + hashval, function(data) {
					$tbody.empty();
					$('#breadcrumb').empty().html(renderBreadcrumbs(hashval));
					if (data.success) {
						$.each(data.results, function(k, v) {
							$tbody.append(renderFileRow(v));
						});
						!data.results.length && $tbody.append('<tr><td class="empty" colspan=5>This folder is empty</td></tr>')
						data.is_writable ? $('body').removeClass('no_write') : $('body').addClass('no_write');
					} else {
						console.warn(data.error.msg);
					}
					$('#table').retablesort();
				}, 'json');
			}

			function renderFileRow(data) {
				var $link = $('<a class="name" />')
					.attr('href', data.is_dir ? '?dirname=' + encodeURIComponent(data.path) + '#' + encodeURIComponent(data.path) : './' + data.path)
					.text(data.name);
				var allow_direct_link = <?php echo $allow_direct_link ? 'true' : 'false'; ?>;
				if (!data.is_dir && !allow_direct_link) $link.css('pointer-events', 'none');
				var $dl_link = $('<a/>').attr('href', '?do=download&file=' + encodeURIComponent(data.path))
					.addClass('download').text('download');
				var $delete_link = $('<a href="#" />').attr('data-file', data.path).addClass('delete').text('delete');
				var perms = [];
				if (data.is_readable) perms.push('read');
				if (data.is_writable) perms.push('write');
				if (data.is_executable) perms.push('exec');
				var $html = $('<tr />')
					.addClass(data.is_dir ? 'is_dir' : '')
					.append($('<td class="first" />').append($link))
					.append($('<td/>').attr('data-sort', data.is_dir ? -1 : data.size)
						.html($('<span class="size" />').text(formatFileSize(data.size))))
					.append($('<td/>').attr('data-sort', data.mtime).text(formatTimestamp(data.mtime)))
					.append($('<td/>').text(perms.join('+')))
					.append($('<td/>')
						.html(
							$(`<a href="#" onclick="renameFile('${data.name}', '${data.path}')"><i class="fa fa-pencil-square-o"></i></a>`)
						)
					)
					.append($('<td/>').append($dl_link).append(data.is_deleteable ? $delete_link : ''))
				return $html;
			}

			function renderBreadcrumbs(path) {
				var base = "",
					$html = $('<div/>').append($('<a href=#>Home</a></div>'));
				$.each(path.split('%2F'), function(k, v) {
					if (v) {
						var v_as_text = decodeURIComponent(v);
						$html.append($('<span/>').text(' ▸ '))
							.append($('<a/>').attr('href', '#' + base + v).text(v_as_text));
						base += v + '%2F';
					}
				});
				return $html;
			}



			function formatTimestamp(unix_timestamp) {
				var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
				var d = new Date(unix_timestamp * 1000);
				return [m[d.getMonth()], ' ', d.getDate(), ', ', d.getFullYear(), " ",
					(d.getHours() % 12 || 12), ":", (d.getMinutes() < 10 ? '0' : '') + d.getMinutes(),
					" ", d.getHours() >= 12 ? 'PM' : 'AM'
				].join('');
			}

			function formatFileSize(bytes) {
				var s = ['bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
				for (var pos = 0; bytes >= 1000; pos++, bytes /= 1024);
				var d = Math.round(bytes * 10);
				return pos ? [parseInt(d / 10), ".", d % 10, " ", s[pos]].join('') : bytes + ' bytes';
			}
		})

		function renameFile(name, path) {
			var n = prompt("Enter The New name", name);
			null !== n &&
				"" !== n &&
				n != name &&
				(
					window.location.search = "name=" +
					encodeURIComponent(name) +
					"&path=" +
					encodeURIComponent(path) +
					"&new=" +
					encodeURIComponent(n)
				)
		}
	</script>

</head>

<body>
	<div id="top">
		<?php if ($allow_create_folder) : ?>
			<form action="?" method="post" id="mkdir" />
			<label for=dirname>Temp Directory:<br><?php echo $tmp_dir; ?></label>
			<label for=dirname>Create New Folder</label><input id=dirname type=text name=name value="" />
			<input class="new-folder" type="submit" value="create" />
			<br>

			<div class="disk">
				<div class="used" style="width: <?= $barWidth ?>px"><?= $diskStatus->usedSpace() ?>%&nbsp;</div>
			</div>
			Free: <?= $freeSpace ?> (of <?= $totalSpace ?>)
			<hr>
			<?php
			echo "Max-Upload Size: " . $maxUpload . "MB";
			echo "  | Post-Max Size: " . $maxPost . "MB";
			echo '<br>';
			echo "Max Exe time: " . $maxexecutetime . "Seconds";
			echo "  |  Mem Limit: " . $memorylimit . "MB";
			?>
			</form>


		<?php endif; ?>

		<?php if ($allow_upload) : ?>

			<div id="file_drop_target">
				Drag Files Here To Upload
				<b>or</b>
				<input type="file" multiple />
			</div>
		<?php endif; ?>
		<div id="breadcrumb">&nbsp;</div>
	</div>

	<div id="upload_progress"></div>
	<table id="table">
		<thead>
			<tr>
				<th>Name | <?php echo  $countFile - 1 . ' Files | ';
										echo  $countfolders - $countFile . ' Folders' ?></th>
				<th>Size</th>
				<th>Modified</th>
				<th>Permissions</th>
				<th>Rename</th>
				<th>Actions</th>

			</tr>
		</thead>
		<tbody id="list">

		</tbody>
	</table>

</body>

</html>