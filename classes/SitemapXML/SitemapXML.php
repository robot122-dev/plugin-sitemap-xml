<?php

namespace SitemapXML;

/**
 * Created by PhpStorm.
 * User: garin
 * Date: 18.08.2016
 * Time: 0:10
 */

use Cetera\Util;

class SitemapXML extends \Cetera\Catalog
{
    protected $id = null;
    /**
     * @var
     */
    protected $host; // Хост сайта
    /**
     * @var string
     */
    protected $scheme = 'http://'; // http или https?
    /**
     * @var array
     */
    protected $urls = array(); // Здесь будут храниться собранные ссылки
    /**
     * @var array
     */
    protected $urlsInfo = array();
    /**
     * @var null
     */
    protected $content = null; // Рабочая переменная
    // Здесь ссылки, которые не должны попасть в sitemap.xml
    /**
     * @var array
     */
    protected $nofollow = array('/cms/');
    // Разрешённые расширения файлов, чтобы не вносить в карту сайта ссылки на медиа файлы. Также разрешены страницы без разрешения, у меня таких страниц подавляющее большинство.
    /**
     * @var array
     */
    protected $ext = [
        'php',
        'htm',
        'html',
    ];
    // Корневая директория сайта, значение можно взять из $_SERVER['DOCUMENT_ROOT'].'/';
    /**
     * @var
     */
    protected $engine_root;
    protected $sitemapPath;
    protected $sitemapTxtPath;
    protected $sitemapLink;
    /**
     * @var bool
     */
    protected $valid = false;
    /**
     * @var string
     */
    protected $savePath;
    /**
     * @var int
     */
    protected $maxLevel;
    /**
     * @var array
     */
    protected $errors = [];
    protected $info = array();
    protected $serviceList = [
        "google" => [
            "url" => "https://www.google.com/webmasters/tools/ping?sitemap=%s%",
            "urlTools" => "https://www.google.com/webmasters/tools/",
            "name" => "Google",
            "configName" => "pingGoogleSuccess"
        ],
        "yandex" => [
            "url" => "https://blogs.yandex.ru/pings/?status=success&url=%s%",
            "urlTools" => "https://webmaster.yandex.ru/",
            "name" => "Yandex",
            "configName" => "pingYandexSuccess"
        ],
        "bing" => [
            "url" => "https://www.bing.com/webmaster/ping.aspx?siteMap=%s%",
            "urlTools" => "http://www.bing.com/toolbox/webmaster/",
            "name" => "Bing",
            "configName" => "pingBingSuccess"
        ],
    ];
    protected $nodes = array();
    protected $showMaterial;
    protected $status;
    protected $excludeDir;
    protected $excludeElement;
    protected $siteInfo;

    /**
     * SitemapXML constructor.
     */
    public function __construct($id = null)
    {
        parent::__construct();
        if (intval($id) > 0)
            $this->id = $id;

        if (intval($this->id) > 0) {
            $qb = \Cetera\DbConnection::getDbConnection()->createQueryBuilder();
            $r = $qb
                ->select('*')
                ->from('sitemapxml_lists')
                ->where($qb->expr()->eq('id', (int)$this->id))
                ->execute();

            $this->info = $r->fetch();
            $this->info["dirs"] = explode(",", $this->info["dirs"]);

            $fileName = $this->info["path"];
            if (!preg_match("#^/#is", $fileName)) {
                $fileName = "/" . $fileName;
            }
            $this->info["path"] = $fileName;

            $this->sitemapPath = DOCROOT . $fileName;

            $fileArr = explode(".", $this->sitemapPath);
            array_pop($fileArr);
            $this->sitemapTxtPath = implode(".", $fileArr) . ".txt";

            if (!empty($this->info["site"])) {
                $this->siteInfo = \Cetera\Catalog::getById($this->info["site"]);
                if ($this->siteInfo) {
                    $this->sitemapLink = $this->siteInfo->getFullUrl() . $this->info["path"];
                }
            }
        }
    }

    public static function parse($id, $full = false)
    {
        $p = new self($id);
        $t = \Cetera\Application::getInstance()->getTranslator();
        if (empty($p->info["site"])) {
            throw new \Exception($t->_("Не выбран сайт"));
        } elseif (empty($p->info["path"])) {
            throw new \Exception($t->_("Не указан путь к файлу"));
        }

        if (!$full) {
            return $p->parseStep();
        } else {
            $p->parseStep(0, 10000);
            $p->parseStep(10, 10000);
            $p->parseStep(20, 10000);
            $p->parseStep(80, 10000);
            $p->parseStep(90, 10000);
            $p->parseStep(95, 10000);
            $p->parseStep(100, 10000);
        }
    }

    protected function parseStep($step = null, $stepDuration = 10)
    {
        $arValueSteps = array(
            'init' => 0,
            'dirs_index' => 10,
            'dirs' => 20,
            'save' => 80,
            'robots' => 90,
            'services' => 95,
            'index' => 100,
        );

        $t = \Cetera\Application::getInstance()->getTranslator();

        $NS = isset($_SESSION['NS'][$this->id]) && is_array($_SESSION['NS'][$this->id]) ? $_SESSION['NS'][$this->id] : array();

        if (!array_key_exists('DIRS_COUNT', $NS)) {
            $NS['DIRS_COUNT'] = 0;
        }
        if (!array_key_exists('CURRENT_DIR_INSERT', $NS)) {
            $NS['CURRENT_DIR_INSERT'] = false;
        }

        if ($step === null) {
            $v = intval($_REQUEST['step']);
        } else {
            $v = $step;
        }

        $PID = $this->id;

        if ($v === $arValueSteps['init']) {
            SitemapRuntimeTable::clearByPid($PID);
            $NS = array();
            $NS['time_start'] = microtime(true);
            $NS['message'] = $t->_("Создание списка разделов...");
            $NS["CURRENT_COUNT"] = 0;
            $v = $arValueSteps["dirs_index"];
        } elseif ($v === $arValueSteps['dirs_index']) {
            $NS['time_start'] = microtime(true);

            $this->excludeDir = array();
            $this->excludeElement = array();
            foreach ($this->info["dirs"] as $dir) {
                if (strpos($dir, "section-") !== false) {
                    $this->excludeDir[] = intval(str_replace("section-", "", $dir));
                } elseif (strpos($dir, "element-") !== false) {
                    $this->excludeElement[] = intval(str_replace("element-", "", $dir));
                }
            }

            // Рекурсивно добавляем все дочерние разделы исключённых директорий.
            // Это необходимо, т.к. в UI дочерние узлы могут быть не раскрыты
            // и их состояние чекбокса не передаётся при сохранении.
            if (!empty($this->excludeDir)) {
                $allExcluded = $this->excludeDir;
                foreach ($this->excludeDir as $excludedId) {
                    $parentRes = self::getList(
                        array(),
                        array('data_id' => $excludedId, 'table' => 'dir_structure'),
                        array(),
                        array('lft', 'rght')
                    );
                    if ($parentInfo = $parentRes->fetch()) {
                        $childrenRes = self::getList(
                            array('data_id' => 'asc'),
                            array(
                                'table'  => 'dir_structure',
                                '>lft'   => $parentInfo['lft'],
                                '<rght'  => $parentInfo['rght'],
                            ),
                            array(),
                            array('data_id')
                        );
                        while ($childRow = $childrenRes->fetch()) {
                            $allExcluded[] = intval($childRow['data_id']);
                        }
                    }
                }
                $this->excludeDir = array_unique($allExcluded);
            }

            $prevID = !empty($NS["LAST_DIR"]) ? $NS["LAST_DIR"] : 0;
            $mainDirInfoRes = self::getList(array(), array("data_id" => $this->info["site"], "table" => "dir_structure"), array(), array("id, lft, rght"));
            if ($mainDirInfo = $mainDirInfoRes->fetch()) {
                if ($prevID === 0) {
                    SitemapRuntimeTable::add(array(
                        'listId' => $PID,
                        'processed' => SitemapRuntimeTable::UNPROCESSED,
                        'dirId' => $this->info["site"],
                    ));
                }

                $ts_finish = microtime(true) + $stepDuration * 0.95;
                $hasDir = false;

                $dirsList = self::getList(array("data_id" => "asc"), array("!data_id" => $this->excludeDir, ">data_id" => $prevID, "table" => "dir_structure", ">=lft" => $mainDirInfo["lft"], "<rght" => $mainDirInfo["rght"]), array(), array("data_id"));

                while (($dir = $dirsList->fetch()) && microtime(true) <= $ts_finish) {
                    SitemapRuntimeTable::add(array(
                        'listId' => $PID,
                        'processed' => SitemapRuntimeTable::UNPROCESSED,
                        'dirId' => $dir["data_id"],
                    ));
                    $NS["LAST_DIR"] = $dir["data_id"];
                    $NS["DIRS_COUNT"]++;
                    $hasDir = true;
                }

                if (!$hasDir)
                    $v = $arValueSteps['dirs'];
                else
                    $v = $arValueSteps["dirs_index"];
                $NS['message'] = $t->_("Индексирование разделов...");
            } else {
                $v = $arValueSteps["index"];
                $NS["message"] = $t->_("Ошибка получения данных.");
            }
        } else if ($v == $arValueSteps['dirs']) {

            $ts_finish = microtime(true) + $stepDuration * 0.95;

            $currentDirFinished = false;
            $bFinished = false;

            while (!$bFinished && microtime(true) <= $ts_finish) {
                if (empty($NS["CURRENT_DIR"])) {
                    $dir = SitemapRuntimeTable::getList(array("id" => "asc"), array("processed" => SitemapRuntimeTable::UNPROCESSED), array("LIMIT" => 1));
                    if ($d = $dir->fetch()) {
                        $NS["CURRENT_DIR"] = $d["dirId"];
                        $NS["CURRENT_DIR_INSERT"] = true;
                        $NS["CURRENT_LIST"] = $d["id"];
                    } else {
                        $bFinished = true;
                        $currentDirFinished = true;
                    }
                }

                if (!$bFinished) {
                    $dir = \Cetera\Catalog::getById($NS["CURRENT_DIR"]);
                    if ($dir) {
                        if (isset($NS['CURRENT_DIR_INSERT']) && $NS["CURRENT_DIR_INSERT"]) {
                            $dirInfo = self::process_child($dir);
                            if (!empty($dirInfo["fullUrl"]) && !empty($dirInfo["alias"])) {
                                SitemapRuntimeTable::addUrl(array(
                                    'url' => $dirInfo["fullUrl"],
                                    'listId' => $this->id,
                                    'priority' => $dirInfo['id'] == $this->info['site'] ? '1' : '0.8',
                                    'date' => $dirInfo['date']
                                ));
                                $NS["message"] = $t->_("Индексирование элементов...");
                            }
                            unset($NS["CURRENT_DIR_INSERT"]);
                        } elseif ((!is_array($this->excludeElement) || !in_array($NS["CURRENT_DIR"], $this->excludeElement)) && $dir->prototype->materialsType) {
                            $where = '';
                            if (isset($NS['LAST_MATERIAL_ID']) && intval($NS["LAST_MATERIAL_ID"]) > 0)
                                $where = 'id > ' . $NS["LAST_MATERIAL_ID"];

                            //$m = $dir->getMaterials('name', $where, "id ASC", '', 100, 0);
                            $m = $dir->getMaterials()->select('name')->where($where)->orderBy("id", "ASC")->setItemCountPerPage(100);
                            $hasMaterials = false;
                            foreach ($m as $material) {
                                $hasMaterials = true;
                                $a = self::process_material($material, 2);
                                if (is_array($a) && !empty($a["fullUrl"]) && !empty($a["alias"])) {
                                    SitemapRuntimeTable::addUrl(array(
                                        'url' => $a["fullUrl"],
                                        'listId' => $this->id,
                                        'priority' => '0.6',
                                        'date' => $a["date"]
                                    ));
                                    $NS["CURRENT_COUNT"]++;
                                    $NS["message"] = $t->_("Индексирование элементов...");
                                }
                                $NS["LAST_MATERIAL_ID"] = $material->id;
                            }

                            if (!$hasMaterials) {
                                unset($NS["CURRENT_DIR"]);
                                unset($NS["LAST_MATERIAL_ID"]);
                                $currentDirFinished = true;
                            }
                        } else {
                            unset($NS["CURRENT_DIR"]);
                            $currentDirFinished = true;
                        }
                    } else {
                        unset($NS["CURRENT_DIR"]);
                        $currentDirFinished = true;
                    }
                }

                if ($currentDirFinished) {
                    SitemapRuntimeTable::setProcessed($NS["CURRENT_LIST"]);
                }
            }

            if ($bFinished) {
                $v = $arValueSteps['save'];
                $NS["message"] = $t->_("Сохранение данных в файл");
            }
        } elseif ($v == $arValueSteps["save"]) {
            $urlList = array();

            $ts_finish = microtime(true) + $stepDuration * 0.95;
            $bFinished = false;
            $where = array("table" => "sitemapxml_urls", "listId" => $this->id, "!processed" => 1);
            /*if (!empty($NS["LAST_SAVE_URL"]))
                $where[">id"] = $NS["LAST_SAVE_URL"];*/

            if (empty($NS["LAST_SAVE_URL"])) {
                //SitemapRuntimeTable::removeDuplicate($this->id);
                self::createFile();
            }

            $arResUrls = SitemapRuntimeTable::getList(array("url" => "asc"), $where, array("LIMIT" => 1000));

            $hasUrls = false;
            $setProcessed = array();
            $urlListCounter = 1;
            $protocolCheck = "http";
            if (str_contains($this->info["domain"], 'https://') != false) {
                $protocolCheck = "https";
            }
            while (($url = $arResUrls->fetch()) && !$bFinished && microtime(true) <= $ts_finish) {
                $url = preg_replace("#([^:])//#is", "$1/", $url);
                $hasUrls = true;

                if (($dt = \DateTime::createFromFormat('Y-m-d G:i:s', $url["lastModified"])) !== false) {
                    $date = $dt->format("c");
                } else {
                    $date = date('c', time());
                }

                $url['url'] = rtrim($url['url'],"/").'/';

                if ($protocolCheck === 'https') {
                    $url['url'] = str_replace( 'http://', 'https://', $url['url']);
                }

                $sitemapXML = "\r\n\t<url>
    \t<loc>{$url['url']}</loc>
    \t<changefreq>weekly</changefreq>
    \t<priority>" . $url["priority"] . "</priority>
    \t<lastmod>" . $date . "</lastmod>
\t</url>";
                $sitemapTXT = $url['url'] . "\r\n";

                self::addToFile($sitemapXML, false, true);
                self::addToFile($sitemapTXT, true, true);
                $setProcessed[$url["id"]] = $url["id"];

                $NS["LAST_SAVE_URL"] = $url["id"];
                $NS["message"] = $t->_("Сохранение данных в файл");
            }

            if (count($setProcessed)) {
                SitemapRuntimeTable::setUrlProcessed($setProcessed);
            }

            if (!$hasUrls)
                $bFinished = true;

            if ($bFinished) {
                
                if (!empty($this->info["robots"])) {
                    $NS["message"] = $t->_("Добавление информации в robots.txt");
                    $v = $arValueSteps['robots'];
                } elseif (!empty($this->info["yandex"]) || !empty($this->info["google"]) || !empty($this->info["bing"])) {
                    $NS["message"] = $t->_("Оповещение поисковых систем");
                    $v = $arValueSteps['services'];
                } else {
                    $v = $arValueSteps['index'];
                }
            }
        } elseif ($v == $arValueSteps["robots"]) {
			
			self::closeFile();

			if (!empty($this->info['cities']) && ($this->info['cities'] == '1')) {
                self::addToRobots($this->info['path'], $this->info['cities']);
				SitemapCities::generateCitiesSitemaps($this->info["domain"], $this->info["path"]);
			} else {
                self::addToRobots($this->info['path'], $this->info['cities']);
            }

            if (!empty($this->info["yandex"]) || !empty($this->info["google"]) || !empty($this->info["bing"])) {
                $NS["message"] = $t->_("Оповещение поисковых систем");
                $v = $arValueSteps['services'];
            } else {
                $v = $arValueSteps['index'];
            }
        } elseif ($v == $arValueSteps["services"]) {

            if (isset($NS["LAST_SERVICES"]) && !is_array($NS["LAST_SERVICES"]))
                $NS["LAST_SERVICES"] = array();

            if (!empty($NS["SERVICE_NAME"])) {
                self::pingService($NS["SERVICE_NAME"]);
                $NS["LAST_SERVICES"][$NS["SERVICE_NAME"]] = $NS["SERVICE_NAME"];
            }

            if (!empty($this->info['yandex']) && isset($NS["LAST_SERVICES"]) && !in_array("yandex", $NS["LAST_SERVICES"])) {
                $NS["SERVICE_NAME"] = "yandex";
                $NS["message"] = $t->_("Оповещение поисковых систем: Яндекс");
            } elseif (!empty($this->info['google']) && isset($NS["LAST_SERVICES"])  && !in_array("google", $NS["LAST_SERVICES"])) {
                $NS["SERVICE_NAME"] = "google";
                $NS["message"] = $t->_("Оповещение поисковых систем: Google");
            } elseif (!empty($this->info['bing']) && isset($NS["LAST_SERVICES"]) && !in_array("bing", $NS["LAST_SERVICES"])) {
                $NS["SERVICE_NAME"] = "bing";
                $NS["message"] = $t->_("Оповещение поисковых систем: Bing");
            } else {
                $v = $arValueSteps['index'];
            }
        }

        if ($v == $arValueSteps['index']) {
            $qb = \Cetera\DbConnection::getDbConnection()->createQueryBuilder();
            $qb
                ->update('sitemapxml_lists')
                ->set('`lastRun`', $qb->expr()->literal(date("Y-m-d H:i:s"), \PDO::PARAM_STR))
                ->where($qb->expr()->eq('id', $this->id))
                ->execute();

            SitemapRuntimeTable::clearByPid($PID);
            $NS["message"] = $t->_("Создание карты сайта успешно выполнено");
        }


        $_SESSION['NS'][$this->id] = $NS;

        return array("id" => $this->id, "step" => $v, "message" => $NS["message"], "NS" => $NS);
    }

    public static function getList($arSort = array(), $arFilter = array(), $arLimit = array(), $arSelect = array())
    {
        $qb = \Cetera\DbConnection::getDbConnection()->createQueryBuilder();
        if (empty($arSelect))
            $arSelect = "*";
        else
            $arSelect = implode(",", $arSelect);

        $qb = $qb
            ->select($arSelect)
            ->from(!empty($arFilter["table"]) ? $arFilter["table"] : static::TABLE);

        $first = true;
        if (count($arFilter)) {
            foreach ($arFilter as $key => $val) {
                if ($key == "table")
                    continue;

                if (is_array($val) && empty($val))
                    continue;

                if (strpos($key, "!") !== false) {
                    $key = preg_replace("#^!#is", "", $key) . (is_array($val) ? " NOT IN (" : " <> ");
                } elseif (strpos($key, ">=") !== false) {
                    $key = preg_replace("#^>=#is", "", $key) . " >= ";
                } elseif (strpos($key, "<=") !== false) {
                    $key = preg_replace("#^<=#is", "", $key) . " <= ";
                } elseif (strpos($key, ">") !== false) {
                    $key = preg_replace("#^>#is", "", $key) . " > ";
                } elseif (strpos($key, "<") !== false) {
                    $key = preg_replace("#^<#is", "", $key) . " < ";
                } else {
                    $key = $key . (is_array($val) ? " IN (" : " = ");
                }

                if ($first) {
                    $value = $val;
                    if (is_array($val)) {
                        $value = "";
                        foreach ($val as $v) {
                            $value .= $qb->createNamedParameter($v) . ",";
                        }
                        $value = trim($value, ",") . ")";
                    }
                    $qb = $qb->where($key . $value);
                } else {
                    $value = $val;
                    if (is_array($val)) {
                        $value = "";
                        foreach ($val as $v) {
                            $value .= $qb->createNamedParameter($v) . ",";
                        }
                        $value = trim($value, ",") . ")";
                    }
                    $qb = $qb->andWhere($key . $value);
                }

                $first = false;
            }
        }

        $first = true;
        if (count($arSort)) {
            foreach ($arSort as $key => $val) {
                if ($first)
                    $qb = $qb->orderBy($key, $val);
                else
                    $qb = $qb->addOrderBy($key, $val);

                $first = false;
            }
        }

        if (count($arLimit)) {
            if (intval($arLimit["LIMIT"]) > 0) {
                $qb = $qb->setMaxResults(intval($arLimit["LIMIT"]));
            }

            if (intval($arLimit["TOP"]) > 0) {
                $qb = $qb->setFirstResult(intval($arLimit["TOP"]));
            }

            if (intval($arLimit["PAGE"]) > 0 && intval($arLimit["PAGE_COUNT"]) > 0) {
                $qb = $qb->setFirstResult((intval($arLimit["PAGE"]) - 1) * intval($arLimit["PAGE_COUNT"]));
                $qb = $qb->setMaxResults(intval($arLimit["PAGE_COUNT"]));
            }
        }

        $r = $qb->execute();

        return $r;
    }

    protected function process_child(\Cetera\Catalog $child, $rule = false, $only = false, $nolink = false, $exclude = false, $nocatselect = false, $level = 0)
    {


        if (!self::prepareLink($child->getFullUrl()))
            return false;

        if ($child->id == $exclude) return false;

         $right = 1;

        if ($only) {
            if ($child->materialsType != $only) {
                $right = 0;
            }
        }

        if (($child->isLink()) && ($nolink)) $right = 0;


        $cls = 'tree-folder-visible';
        if ($child instanceof \Cetera\Server) $cls = 'tree-server';
        if ($child->isLink()) $cls = 'tree-folder-link';
        if ($child->hidden) $cls = 'tree-folder-hidden';

        return array(
            'text' => $child->name,
            'alias' => $child->alias,
            'fullUrl' => $child->getFullUrl(),
            'id' => $child->id,
            'qtip' => $child->describ,
            'link' => $child->isLink(),
            'mtype' => $child->materialsType,
            'hidden' => $child->hidden,
            'date' => $child->dat,
            'iconCls' => $cls,
            'disabled' => ($right && !$nocatselect) ? false : true,
        );
    }

    protected function prepareLink($link)
    {
        //Если не установлена схема и хост ссылки, то подставляем наш хост
        if (preg_match("#^/#is", $link) && !strstr($link, $this->scheme . $this->host)) {
            $link = $this->scheme . $this->host . $link;
        }

        //Убираем якори у ссылок
        $link = preg_replace("/#.*/X", "", $link);

        //Узнаём информацию о ссылке
        $urlinfo = @parse_url($link);
        if (!isset($urlinfo['path'])) {
            $urlinfo['path'] = null;
        }

        //Если ссылка в нашем запрещающем списке, то также прекращаем с ней работать
        $nofoll = 0;
        if ($this->nofollow != null) {
            foreach ($this->nofollow as $of) {
                if (strstr($link, $of)) {
                    $nofoll = 1;
                    break;
                }
            }
        }

        if ($nofoll == 1) {
            return false;
        }

        return true;
    }

    protected function process_material(\Cetera\Material $material, $level)
    {
        if ($material->alias === "index")
            return false;

        if (!self::prepareLink($material->getFullUrl()))
            return false;

        $name = htmlspecialchars($material->name);
        $name = str_replace("\n", '', $name);
        $name = str_replace("\r", '', $name);

        if ($material->hidden)
            return false;

        return array(
            'text' => $name,
            'alias' => $material->alias,
            'fullUrl' => $material->getFullUrl(),
            'id' => $material->id,
            'qtip' => $material->describ,
            'link' => $material->isLink(),
            'mtype' => $material->materialsType,
            'hidden' => $material->hidden,
            'date' => $material->dat
        );
    }

    protected function createFile()
    {
        $sitemapXML = '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.google.com/schemas/sitemap/0.84"
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
xsi:schemaLocation="http://www.google.com/schemas/sitemap/0.84 http://www.google.com/schemas/sitemap/0.84/sitemap.xsd">
<!-- Last update of sitemap ' . date("Y-m-d H:i:s+06:00") . ' -->';
        self::addToFile($sitemapXML);
        self::addToFile("", true);
    }

    protected function addToFile($text, $txt = false, $append = false)
    {
        if (empty($this->sitemapPath)) {
            $fileName = $this->info["path"];
            if (!preg_match("#^/#is", $fileName)) {
                $fileName = "/" . $fileName;
            }

            $filePath = DOCROOT . $fileName;
            $this->sitemapPath = $filePath;
        }
        $filePath = $this->sitemapPath;

        if ($txt) {
            $fileArr = explode(".", $this->sitemapPath);
            array_pop($fileArr);
            $filePath = implode(".", $fileArr) . ".txt";
        }

        if ($append) {
            file_put_contents($filePath, $text, FILE_APPEND);
        } else {
            file_put_contents($filePath, $text);
        }
    }

    protected function closeFile()
    {
        $sitemapXML = "\r\n</urlset>";
        self::addToFile($sitemapXML, false, true);
    }

    public function addToRobots($path = 'sitemap.xml', $cities = 0)
    {
        if (file_exists($this->sitemapPath)) {
            if (!empty($this->info["robots"])) {
                $robotsPath = DOCROOT . "/robots.txt";
                $pathNoXML = str_replace('.xml','',$path);
                $pathNoXMLIndex = $pathNoXML;
                if ($cities == 1) {
                    $pathNoXMLIndex .= '_index';
                }
                $sitemapLinkIndex = str_replace('/'.$pathNoXML,''.$pathNoXMLIndex, $this->sitemapLink);
                $canWriteRobots = file_exists($robotsPath)
                    ? is_writable($robotsPath)
                    : is_writable(dirname($robotsPath));
                if (!$canWriteRobots) {
                    return;
                }

                if (file_exists($robotsPath)) {
                    $fileContent = file_get_contents($robotsPath);
                    if (preg_match("#Sitemap: " . addslashes($this->siteInfo->getFullUrl()) . "#", $fileContent)) {
                        $fileContent = preg_replace("#Sitemap: " . addslashes($this->siteInfo->getFullUrl()) . ".*?$#", "Sitemap: " . $sitemapLinkIndex, $fileContent);
                    }
                    if (!preg_match("#Sitemap: " . $sitemapLinkIndex . "#s", $fileContent)) {
                        $fileContent .= "Sitemap: " . $sitemapLinkIndex . "\n";
                    }
                } else {
                    $fileContent = "Sitemap: " . $sitemapLinkIndex;
                }
                file_put_contents($robotsPath, $fileContent);
            }
        }
    }

    public function pingService($serviceID)
    {
        if (file_exists($this->sitemapPath)) {
            if (!array_key_exists($serviceID, $this->serviceList)) {
                return;
            }
            $url = str_replace("%s%", urlencode($this->sitemapLink), $this->serviceList[$serviceID]["url"]);
            try {
                $code = @file_get_contents($url);
            } catch (\Exception $e) {
            }
        }
    }

    public static function getTreeList($id, $nodeId, $root = false)
    {
        $p = new self($id);
        $mainNode = $p->process_child(\Cetera\Catalog::getById($nodeId));
        $mainNode["expanded"] = true;
        $mainNode["elements"] = "";
        $mainNode["children"] = $p->getFullTree($nodeId);

        return $mainNode;

    }

    public function getFullTree($nodeId)
    {
        return !empty($nodeId) ? self::getTree($nodeId, -1) : null;
    }

    function getTree($id, $level = 0)
    {
        $t = \Cetera\Application::getInstance()->getTranslator();
        $exclude = -1;
        $rule = Util::get('rule');
        $nolink = Util::get('nolink', true);
        $only = Util::get('only', true);
        $materials = !empty($this->showMaterial) ? true : false;
        $nocatselect = Util::get('nocatselect', true);
        $exclude_mat = Util::get('exclude_mat', true);
        $matsort = Util::get('matsort');
        $nodes = array();
        $level++;

        $c = \Cetera\Catalog::getById($id);
        if ($c) {
            foreach ($c->children->hidden(true) as $child) {

                $a = self::process_child($child, $rule, $only, $nolink, $exclude, $nocatselect, $level);
                if(isset($a["children"]) && is_array($a["children"])){
                    $a["children"] = self::getTree($a["id"], $level);
                    $a["children"] = self::array_delete($a["children"], array('', 0, false, null));
                }
                if (is_array($a)) {
                    if (isset($this->info["dirs"]) && is_array($this->info["dirs"]) && count($this->info["dirs"])) {
                        $a['checked'] = !in_array("section-" . $a["id"], $this->info["dirs"]) ? true : false;
                        $hide = !in_array("element-" . $a["id"], $this->info["dirs"]) ? false : true;
                        $a['elements'] = "<a href='#' data-id='" . $a["id"] . "' data-status='" . ($hide ? 'N' : 'Y') . "' class='js-element-hide'>" . ($t->_($hide ? 'Нет' : 'Да')) . "</a>";
                    } else {
                        $a['checked'] = true;
                        $hide = false;
                        $a['elements'] = "<a href='#' data-id='" . $a["id"] . "' data-status='" . ($hide ? 'N' : 'Y') . "' class='js-element-hide'>" . ($t->_($hide ? 'Нет' : 'Да')) . "</a>";
                    }
                    $nodes[] = $a;
                }
            }
            if ($c->isLink()) {
                foreach ($c->prototype->children as $child) {
                    $a = self::process_child($child, $rule, $only, $nolink, $exclude, $nocatselect, $level);
                    $a["children"] = self::getTree($a["id"], $level);
                    $a["children"] = self::array_delete($a["children"], array('', 0, false, null));
                    if (is_array($a)) {
                        if (isset($this->info["dirs"]) && is_array($this->info["dirs"]) && count($this->info["dirs"])) {
                            $a['checked'] = !in_array("section-" . $a["id"], $this->info["dirs"]) ? true : false;
                            $hide = !in_array("element-" . $a["id"], $this->info["dirs"]) ? false : true;
                            $a["parseElements"] = $hide ? false : true;
                            $a['elements'] = "<a href='#' data-id='" . $a["id"] . "' data-status='" . $hide . "' class='js-element-hide'>" . ($t->_($hide ? 'Нет' : 'Да')) . "</a>";
                        } else {
                            $a['checked'] = true;
                            $hide = false;
                            $a["parseElements"] = $hide ? false : true;
                            $a['elements'] = "<a href='#' data-id='" . $a["id"] . "' data-status='" . $hide . "' class='js-element-hide'>" . ($t->_($hide ? 'Нет' : 'Да')) . "</a>";
                        }

                        $nodes[] = $a;
                    }
                }
            }

            if ($materials && $c->prototype->materialsType) {
                $where = 'id<>' . $exclude_mat;

                $m = $c->getMaterials('name', $where, $matsort, '', 200, 0);
                foreach ($m as $material) {
                    $a = self::process_material($material, $level);
                    if (is_array($a)) {
                        $nodes[] = $a;
                    }
                }
            }
        }
        $nodes = self::array_delete($nodes, array('', 0, false, null));

        return $nodes;
    }

    /**
     * Удалить пустые элементы из массива
     *
     * @param array $array
     * @param array $symbols удаляемые значения
     *
     * @return array
     */
    public static function array_delete(array $array = array(), array $symbols = array())
    {
        foreach($array as $ar){
            foreach ($symbols as $empty){
                if($ar == $empty){
                    unset($ar);
                }
            }
        }
        return $array;
    }

    public function getUrlList()
    {
        $this->nodes = array_merge($this->nodes, self::getTree(0, -1));
    }
}
