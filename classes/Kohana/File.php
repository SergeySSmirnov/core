<?php
/**
 * File helper class.
 *
 * @package    Kohana
 * @category   Helpers
 * @author     Kohana Team
 * @copyright  (c) Kohana Team
 * @license    https://koseven.ga/LICENSE.md
 */
class Kohana_File {

	/**
	 * @var bool Allow GZIP compression.
	 * Default value: true.
	 */
	public static $allowedGZIP = true;

	/**
	 * @var bool Allow minify CSS.
	 * Default value: true.
	 */
	public static $allowMinifyCSS = true;

	/**
	 * @var bool Allow minify JS.
	 * Default value: true.
	 */
	public static $allowMinifyJS = true;


	/**
	 * Attempt to get the mime type from a file. This method is horribly
	 * unreliable, due to PHP being horribly unreliable when it comes to
	 * determining the mime type of a file.
	 *
	 *     $mime = File::mime($file);
	 *
	 * @param   string  $filename   file name or path
	 * @return  string  mime type on success
	 * @return  FALSE   on failure
	 */
	public static function mime($filename)
	{
		// Get the complete path to the file
		$filename = realpath($filename);

		// Get the extension from the filename
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		if (preg_match('/^(?:jpe?g|png|[gt]if|bmp|swf)$/', $extension))
		{
			// Use getimagesize() to find the mime type on images
			$file = getimagesize($filename);

			if (isset($file['mime']))
				return $file['mime'];
		}

		if (class_exists('finfo', FALSE))
		{
			if ($info = new finfo(defined('FILEINFO_MIME_TYPE') ? FILEINFO_MIME_TYPE : FILEINFO_MIME))
			{
				return $info->file($filename);
			}
		}

		if (ini_get('mime_magic.magicfile') AND function_exists('mime_content_type'))
		{
			// The mime_content_type function is only useful with a magic file
			return mime_content_type($filename);
		}

		if ( ! empty($extension))
		{
			return File::mime_by_ext($extension);
		}

		// Unable to find the mime-type
		return FALSE;
	}

	/**
	 * Return the mime type of an extension.
	 *
	 *     $mime = File::mime_by_ext('png'); // "image/png"
	 *
	 * @param   string  $extension  php, pdf, txt, etc
	 * @return  string  mime type on success
	 * @return  FALSE   on failure
	 */
	public static function mime_by_ext($extension)
	{
		// Load all of the mime types
		$mimes = Kohana::$config->load('mimes');

		return isset($mimes[$extension]) ? $mimes[$extension][0] : FALSE;
	}

	/**
	 * Lookup MIME types for a file
	 *
	 * @see Kohana_File::mime_by_ext()
	 * @param string $extension Extension to lookup
	 * @return array Array of MIMEs associated with the specified extension
	 */
	public static function mimes_by_ext($extension)
	{
		// Load all of the mime types
		$mimes = Kohana::$config->load('mimes');

		return isset($mimes[$extension]) ? ( (array) $mimes[$extension]) : array();
	}

	/**
	 * Lookup file extensions by MIME type
	 *
	 * @param   string  $type File MIME type
	 * @return  array   File extensions matching MIME type
	 */
	public static function exts_by_mime($type)
	{
		static $types = array();

		// Fill the static array
		if (empty($types))
		{
			foreach (Kohana::$config->load('mimes') as $ext => $mimes)
			{
				foreach ($mimes as $mime)
				{
					if ($mime == 'application/octet-stream')
					{
						// octet-stream is a generic binary
						continue;
					}

					if ( ! isset($types[$mime]))
					{
						$types[$mime] = array( (string) $ext);
					}
					elseif ( ! in_array($ext, $types[$mime]))
					{
						$types[$mime][] = (string) $ext;
					}
				}
			}
		}

		return isset($types[$type]) ? $types[$type] : FALSE;
	}

	/**
	 * Lookup a single file extension by MIME type.
	 *
	 * @param   string  $type  MIME type to lookup
	 * @return  mixed          First file extension matching or false
	 */
	public static function ext_by_mime($type)
	{
		return current(File::exts_by_mime($type));
	}

	/**
	 * Split a file into pieces matching a specific size. Used when you need to
	 * split large files into smaller pieces for easy transmission.
	 *
	 *     $count = File::split($file);
	 *
	 * @param   string  $filename   file to be split
	 * @param   integer $piece_size size, in MB, for each piece to be
	 * @return  integer The number of pieces that were created
	 */
	public static function split($filename, $piece_size = 10)
	{
		// Open the input file
		$file = fopen($filename, 'rb');

		// Change the piece size to bytes
		$piece_size = floor($piece_size * 1024 * 1024);

		// Write files in 8k blocks
		$block_size = 1024 * 8;

		// Total number of pieces
		$pieces = 0;

		while ( ! feof($file))
		{
			// Create another piece
			$pieces += 1;

			// Create a new file piece
			$piece = str_pad($pieces, 3, '0', STR_PAD_LEFT);
			$piece = fopen($filename.'.'.$piece, 'wb+');

			// Number of bytes read
			$read = 0;

			do
			{
				// Transfer the data in blocks
				fwrite($piece, fread($file, $block_size));

				// Another block has been read
				$read += $block_size;
			}
			while ($read < $piece_size);

			// Close the piece
			fclose($piece);
		}

		// Close the file
		fclose($file);

		return $pieces;
	}

	/**
	 * Join a split file into a whole file. Does the reverse of [File::split].
	 *
	 *     $count = File::join($file);
	 *
	 * @param   string  $filename   split filename, without .000 extension
	 * @return  integer The number of pieces that were joined.
	 */
	public static function join($filename)
	{
		// Open the file
		$file = fopen($filename, 'wb+');

		// Read files in 8k blocks
		$block_size = 1024 * 8;

		// Total number of pieces
		$pieces = 0;

		while (is_file($piece = $filename.'.'.str_pad($pieces + 1, 3, '0', STR_PAD_LEFT)))
		{
			// Read another piece
			$pieces += 1;

			// Open the piece for reading
			$piece = fopen($piece, 'rb');

			while ( ! feof($piece))
			{
				// Transfer the data in blocks
				fwrite($file, fread($piece, $block_size));
			}

			// Close the piece
			fclose($piece);
		}

		return $pieces;
	}

	/**
	 * Minify the CSS files passed in the parameter to a single file. External files are not minified. The path to the minifyed is: /media/css/auto/{md5_filename}.css.
	 * @param array $files The list of files to be minified.
	 * @return array List of minifyed files.
	 */
	public static function minifyCSS(array $files) {
		if (!self::$allowMinifyCSS)
			return $files;
		$_cssResult = []; // Результирующий массив CSS-стилей
		$_cssLocals = []; // Локальные CSS-стили, на основе которых будет сгенерирован единственный файл
		$_md5FName = ''; // MD5 сумма названий файлов
		foreach ($files as $_key => $_val) { // Пробегаемся по всем CSS-стилям
			if (Text::startsWith($_key, 'http') || Text::startsWith($_key, '//')) { // Если это внешний CSS-стиль, то пропускаем его
				$_cssResult[$_key] = $_val;
				continue;
			}
			$_fileName = DOCROOT.((substr($_key, 0, 1) === DIRECTORY_SEPARATOR) ? substr($_key, 1) : $_key); // Формируем имя файла
			if (file_exists($_fileName)) { // Если файл существует, то добавляем его в список локальных для объединения
				$_cssLocals[] = $_fileName;
				$_md5FName .= $_key.filemtime($_fileName);
			}
		}
		$_md5FName = (self::$allowedGZIP ? 'gz' : '').md5($_md5FName).'.css'; // Имя конечного файла
		$_filePath = DOCROOT.'media/css/auto/';
		$_md5FileName = $_filePath.$_md5FName; // Формируем результирующее имя CSS-файла
		if (!file_exists($_md5FileName)) { // Если объединённый файл не существует, то формируем его
			if (!file_exists($_filePath)) // Если каталога не существует, то создаём его
				mkdir($_filePath, 0770, true);
			$_cssFile = self::$allowedGZIP ? gzopen($_md5FileName, 'wb9') : fopen($_md5FileName, 'w'); // Создаем новый файл
			foreach ($_cssLocals as $_val) { // Пробегаемся по всем локальным файлам
				$_fContent = file_get_contents($_val);
				$_fContent = preg_replace("/\/\*[\d\D]*?\*\/|\t+/", " ", $_fContent); // Удаляем кооменты
				$_fContent = str_replace(["\n", "\r", "\t"], " ", $_fContent); // Заменяем CR, LF и TAB на пробелы
				$_fContent = preg_replace("/\s\s+/", " ", $_fContent); // Заменяем множественные пробелы на одиночные
				$_fContent = preg_replace("/\s*({|}|\[|\]|=|~|\+|>|\||;|:|,)\s*/", "$1", $_fContent); // Удаляем ненужные пробелы
				$_fContent = str_replace(";}", "}", $_fContent); // Удаляем точку запятой в последней строке правила
				$_fContent = trim($_fContent);
				if (self::$allowedGZIP)
					gzwrite($_cssFile, $_fContent);
				else
					fwrite($_cssFile, $_fContent);
			}
			if (self::$allowedGZIP)
				gzclose($_cssFile);
			else
				fclose($_cssFile);
		}
		$_cssResult['/media/css/'.(self::$allowedGZIP ? '' : 'auto/').$_md5FName] = ''; // Завершаем формирование результирующего списка
		return $_cssResult;
	}

	/**
	 * Minify the JS files passed in the parameter to a single file. External files are not minified. The path to the minifyed is: /media/js/auto/{md5_filename}.js.
	 * @param array $files The list of files to be minified.
	 * @return array List of minifyed files.
	 */
	public static function minifyJS(array $files) {
		if (!self::$allowMinifyJS)
			return $files;
		$_jsResult = []; // Результирующий массив JS-скриптов
		$_jsLocals = []; // Локальные JS-скрипты, на основе которых будет сгенерирован единственный файл
		$_md5FName = ''; // MD5 сумма названий файлов
		foreach ($files as $_val) { // Пробегаемся по всем JS-файлам
			if (Text::startsWith($_val, 'http') || Text::startsWith($_val, '//') || Text::endsWith($_val, '.min.js')) { // Если это внешний или минифицированный JS-скрипт, то пропускаем его
				$_jsResult[] = $_val;
				continue;
			}
			$_fileName = DOCROOT.((substr($_val, 0, 1) === DIRECTORY_SEPARATOR) ? substr($_val, 1) : $_val); // Формируем имя файла
			if (file_exists($_fileName)) { // Если файл существует, то добавляем его в список локальных для объединения
				$_jsLocals[] = $_fileName;
				$_md5FName .= $_val.filemtime($_fileName);
			}
		}
		$_md5FName = (self::$allowedGZIP ? 'gz' : '').md5($_md5FName).'.js'; // Имя конечного файла
		$_filePath = DOCROOT.'media/js/auto/';
		$_md5FileName = $_filePath.$_md5FName; // Формируем результирующее имя JS-файла
		if (!file_exists($_md5FileName)) { // Если объединённый файл не существует, то формируем его
			if (!file_exists($_filePath)) // Если каталога не существует, то создаём его
				mkdir($_filePath, 0770, true);
			require_once SYSPATH.'vendors/JavaScriptPacker.php';
			$_jsFile = self::$allowedGZIP ? gzopen($_md5FileName, 'wb9') : fopen($_md5FileName, 'w'); // Создаем новый файл
			foreach ($_jsLocals as $_val) { // Пробегаемся по всем локальным файлам
				$_fContent = file_get_contents($_val);
				$packer = new JavaScriptPacker($_fContent, 'None', true, false);
				if (self::$allowedGZIP)
					gzwrite($_jsFile, $packer->pack());
				else
					fwrite($_jsFile, $packer->pack());
			}
			if (self::$allowedGZIP)
				gzclose($_jsFile);
			else
				fclose($_jsFile);
		}
		$_jsResult[] = '/media/js/'.(self::$allowedGZIP ? '' : 'auto/').$_md5FName; // Завершаем формирование результирующего списка
		return $_jsResult;
	}

}
