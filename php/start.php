<?php
/**
 * Node服务配置同步脚本
 
 * 命令行参数：
 * php start.php insert [--no-scan]   # 插入模式，可选是否跳过扫描
 * php start.php cover                 # 覆盖模式
 * php start.php scan-only [type]      # 只扫描生成本地config.json，type可选: text, script, all
 * php start.php template              # 模板模式：从PHP接口获取配置并替换本地配置
 * 
 * scan-only模式新增参数：
 * php start.php scan-only text        # 只扫描纯文本文件(txt,m3u,json)
 * php start.php scan-only script      # 只扫描脚本文件(php,py,js,wv.js)
 * php start.php scan-only all         # 扫描所有文件（默认）
 */

// --------------------------------------------------------------------------
// 1. 全局配置定义
// --------------------------------------------------------------------------

// Node服务的基础URL地址，格式：http://IP:端口
define('NODE_SERVICE_URL', 'http://127.0.0.1:5757');

// Node服务提供的配置API接口地址
define('NODE_CONFIG_API', NODE_SERVICE_URL . '/config/1?healthy=1&pwd=dzyyds');

// 本地配置文件的完整路径（脚本所在目录）
define('LOCAL_CONFIG_FILE', __DIR__ . '/config.json');

// 基准模板配置文件的完整路径（脚本所在目录的同层级/lib/config.json）
define('BASE_TEMPLATE_FILE', __DIR__ . '/lib/config.json');

// 插入模式过滤配置（道长站点sites.api字段包含这些关键词将被排除:也可认为就是道长源的名称，可带后缀）
define('INSERT_MODE_FILTER', ['AppV1', 'AppV2', 'AppV3', 'push_agent']);

// 覆盖模式过滤配置（道长站点sites.api字段包含这些关键词将被排除:也可认为就是道长源的名称，可带后缀。但不包括推送站点:push_agent')
define('COVER_MODE_FILTER', ['AppV1', 'AppV2', 'AppV3']);

// HTTP请求超时时间（秒）
define('HTTP_TIMEOUT', 10);

// 覆盖模式中需要保留config.json的关键词列表（config.json内站点sites.api字段包含这些关键词将被保留）
define('COVER_KEEP_KEYWORDS', ['config', 'start', 'Resources', 'peekpili']);

// 默认PHP端口（当检测失败时使用）
define('DEFAULT_PHP_PORT', 9980);

// 扫描层级限制（0表示只扫描当前目录，1表示扫描一级子目录，依此类推）
define('MAX_SCAN_DEPTH', 5);

// XBPQ目录关键词配置（用于识别XBPQ目录，支持多个关键词）
define('XBPQ_DIR_KEYWORDS', ['PQ类[XBPQ]']);
// XYQ目录关键词配置（用于识别XYQ目录）
define('XYQ_DIR_KEYWORDS', ['YQ类[XYQ]']);
// Drpy2目录关键词配置（用于识别Drpy2目录）
define('DRPY2_DIR_KEYWORDS', ['JS[Drpy]']);
// 如果您想要添加多个关键词来识别XBPQ目录，可以这样配置：
// define('XBPQ_DIR_KEYWORDS', ['XBPQ类[XBPQ]', 'XBPQ', '小白盘搜索']);
// --------------------------------------------------------------------------
// 道长线路里json配置短剧的短剧关键词配置（用于专享道长线路coverMode中识别短剧并设置类型）
define('DRAMA_KEYWORD', '短剧');
// --------------------------------------------------------------------------
// 2. 辅助函数：动态检测PHP服务端口
// --------------------------------------------------------------------------

/**
 * 检测PHP服务端口（通过进程检测）
 * @return int 检测到的PHP服务端口，失败则返回默认端口
 */
function detectPhpPort() {
    echo "🔍 检测PHP服务端口... ";
    
    // 方法1: 使用ps aux命令
    $port = detectPortByCommand('ps aux');
    if ($port > 0) {
        echo "✅ 检测到端口: {$port}\n";
        return $port;
    }
    
    // 方法2: 使用pgrep命令
    $port = detectPortByCommand('pgrep -lf php');
    if ($port > 0) {
        echo "✅ 检测到端口: {$port}\n";
        return $port;
    }
    
    // 方法3: 使用shell组合命令
    $port = detectPortByShell('ps aux | grep php | grep -v grep');
    if ($port > 0) {
        echo "✅ 检测到端口: {$port}\n";
        return $port;
    }
    
    // 方法4: 使用/proc文件系统（Linux专用）
    $port = detectPortFromProc();
    if ($port > 0) {
        echo "✅ 检测到端口: {$port}\n";
        return $port;
    }
    
    // 所有方法都失败，使用默认端口
    echo "⚠️  无法检测到PHP服务端口，使用默认端口: " . DEFAULT_PHP_PORT . "\n";
    return DEFAULT_PHP_PORT;
}

/**
 * 通过执行命令检测端口
 * @param string $command 要执行的命令
 * @return int 检测到的端口，失败返回0
 */
function detectPortByCommand($command) {
    try {
        $output = [];
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            foreach ($output as $line) {
                if (stripos($line, 'php') !== false && stripos($line, 'grep') === false) {
                    // 尝试在行中查找端口
                    if (preg_match('/:(\d{4,5})/', $line, $matches)) {
                        $port = intval($matches[1]);
                        if ($port >= 1024 && $port <= 65535) {
                            return $port;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 静默失败，继续尝试其他方法
    }
    
    return 0;
}

/**
 * 通过shell管道检测端口
 * @param string $shellCommand shell命令
 * @return int 检测到的端口，失败返回0
 */
function detectPortByShell($shellCommand) {
    try {
        $output = shell_exec($shellCommand);
        if ($output) {
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if (trim($line) && stripos($line, 'php') !== false) {
                    if (preg_match('/:(\d{4,5})/', $line, $matches)) {
                        $port = intval($matches[1]);
                        if ($port >= 1024 && $port <= 65535) {
                            return $port;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 静默失败，继续尝试其他方法
    }
    
    return 0;
}

/**
 * 从/proc文件系统检测端口（Linux专用）
 * @return int 检测到的端口，失败返回0
 */
function detectPortFromProc() {
    if (!is_dir('/proc')) {
        return 0; // 不是Linux系统
    }
    
    try {
        $pids = scandir('/proc');
        foreach ($pids as $pid) {
            if (!is_numeric($pid)) {
                continue;
            }
            
            $cmdlineFile = "/proc/{$pid}/cmdline";
            if (file_exists($cmdlineFile)) {
                $cmdline = file_get_contents($cmdlineFile);
                if (stripos($cmdline, 'php') !== false) {
                    // 检查进程是否在监听端口
                    $netFile = "/proc/{$pid}/net/tcp";
                    if (file_exists($netFile)) {
                        $netContent = file_get_contents($netFile);
                        $lines = explode("\n", $netContent);
                        foreach ($lines as $line) {
                            if (strpos($line, ':') !== false) {
                                $parts = preg_split('/\s+/', trim($line));
                                if (count($parts) >= 4) {
                                    $localAddr = $parts[1];
                                    if (strpos($localAddr, ':') !== false) {
                                        $portHex = substr($localAddr, strrpos($localAddr, ':') + 1);
                                        $port = hexdec($portHex);
                                        if ($port >= 1024 && $port <= 65535) {
                                            return $port;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 静默失败
    }
    
    return 0;
}

// --------------------------------------------------------------------------
// 3. 执行扫描功能并生成配置
function generateLocalConfig($scanType = 'all') {
    // --------------------------------------------------------------------------
    // 1. 全局定义与排除策略
    // --------------------------------------------------------------------------

    /**
     * 独立文件类型配置（每种文件类型都有独立完整配置）
     */
    
    // 1. 纯文本文件配置
    $textConfig = [
        'type' => 3,
        'api' => 'csp_FileSpider',
        'jar' => './lib/WvSpider.jar',
        'searchable' => 0,
        'quickSearch' => 0,
        'filterable' => 0,
        'switchable' => 0,
        'changeable' => 0
    ];
    
    // 2. wv.js文件配置
    $wvjsConfig = [
        'type' => 3,
        'api' => 'csp_WvSpider',
        'jar' => './lib/WvSpider.jar',
        'searchable' => 1,
        'filterable' => 1,
        'switchable' => 1
    ];
    
    // 3. PHP文件配置
    $phpConfig = [
        'type' => 4,
        'searchable' => 1,
        'quickSearch' => 1,
        'filterable' => 1,
        'changeable' => 1
    ];
    
    // 4. JS文件配置
    $jsConfig = [
        'type' => 3,
        'searchable' => 1,
        'filterable' => 1,
        'quickSearch' => 1,
        'switchable' => 1,
        'changeable' => 1
    ];
    
    // 5. PY文件配置
    $pyConfig = [
        'type' => 3,
        'searchable' => 1,
        'quickSearch' => 1,
        'filterable' => 1,
        'switchable' => 1
    ];
    
    // 6. XBPQ目录配置
    $xbpqConfig = [
        'type' => 3,
        'api' => 'csp_XBPQ',
        'jar' => './lib/XBPQ.jar',
        'searchable' => 1,
        'quickSearch' => 1,
        'filterable' => 1,
        'changeable' => 1
    ];
    
    // 7. XYQ目录配置
    $xyqConfig = [
        'type' => 3,
        'api' => 'csp_XYQHiker',
        'jar' => './lib/XYQ.jar',
        'searchable' => 1,
        'quickSearch' => 1,
        'filterable' => 1,
        'changeable' => 1
    ];
    
    // 8. Drpy2目录配置
    $drpy2Config = [
        'type' => 3,
        'api' => './lib/lib/drpy2.min.js',
        'searchable' => 1,
        'quickSearch' => 1,
        'filterable' => 1,
        'changeable' => 1
    ];

    // 动态检测PHP服务端口
    $phpPort = detectPhpPort();
    $phpApiPrefix = "http://127.0.0.1:{$phpPort}";

    // 根据扫描类型设置允许处理的文件后缀
    $allowedExtensions = [];
    if ($scanType === 'text') {
        $allowedExtensions = ['txt', 'm3u', 'json'];
    } elseif ($scanType === 'script') {
        $allowedExtensions = ['js', 'wv.js', 'php', 'py'];
    } else { // all
        $allowedExtensions = ['js', 'wv.js', 'txt', 'm3u', 'php', 'py', 'json'];
    }

    // 排除遍历的目录
    $excludeDirs = ['lib', '直播转点播辅助文件', 'lz', '.', '..'];

    // 指定排除的文件名（不输出至配置）
    $excludeFiles = ['index.php', 'test_runner.php', 'config.php', 'start.py'];

    // 直播资源目录名
    $liveDirName = '直播文件';

    // --------------------------------------------------------------------------
    // 2. 站点隐藏配置开关
    // --------------------------------------------------------------------------

    /**
     * 站点文件隐藏开关 - 控制目录名带"[秘]"的站点是否隐藏
     * true: 隐藏 (hide: 1)
     * false: 不隐藏 (hide: 0)
     */
    $hideSecretSites = true; // 可根据需要修改为 true 或 false

    // --------------------------------------------------------------------------
    // 3. 扫描引擎
    // --------------------------------------------------------------------------

    $currentDir = __DIR__;
    $scannedSites = [];
    $scannedLives = [];

    /**
     * 辅助：提取后缀信息（支持双重后缀）
     */
    function getFileExtensionInfo($filename) {
        $parts = explode('.', $filename);
        $count = count($parts);
        if ($count >= 3) {
            return [
                'full'   => $parts[$count - 2] . '.' . $parts[$count - 1],
                'simple' => $parts[$count - 1],
                'name'   => implode('.', array_slice($parts, 0, $count - 2))
            ];
        }
        return [
            'full'   => pathinfo($filename, PATHINFO_EXTENSION),
            'simple' => pathinfo($filename, PATHINFO_EXTENSION),
            'name'   => pathinfo($filename, PATHINFO_FILENAME)
        ];
    }

    /**
     * 从目录名中提取标签 - 提取所有类型的标签
     * @param string $dirName 目录名
     * @return string 提取的标签，如[电影]、[电视剧]等
     */
    function extractTagFromDirName($dirName) {
        // 匹配所有可能的标签格式：[xxx]
        if (preg_match('/\[([^\]]+)\]/', $dirName, $matches)) {
            return '[' . $matches[1] . ']';
        }
        return '';
    }

    /**
     * 检查目录名是否包含特定标签并返回处理后的标签
     * @param string $dirName 目录名
     * @return array [过滤后的标签, 目录类型('book', 'comic', 'drama', 'other')]
     */
    function checkDirTypeAndFilterTag($dirName) {
        // 检查是否包含[书]、[画]、[短剧]标签
        $isBookDir = (strpos($dirName, '[书]') !== false);
        $isComicDir = (strpos($dirName, '[画]') !== false);
        $isDramaDir = (strpos($dirName, '[短剧]') !== false);
        
        // 复制目录名用于处理
        $processedDirName = $dirName;
        
        // 如果是书、漫画、短剧目录，移除对应的标签
        $tagToRemove = '';
        $dirType = 'other';
        if ($isBookDir) {
            $tagToRemove = '[书]';
            $dirType = 'book';
        } elseif ($isComicDir) {
            $tagToRemove = '[画]';
            $dirType = 'comic';
        } elseif ($isDramaDir) {
            $tagToRemove = '[短剧]';
            $dirType = 'drama';
        }
        
        if ($tagToRemove !== '') {
            $processedDirName = str_replace($tagToRemove, '', $processedDirName);
        }
        
        // 从处理后的目录名中提取其他标签
        $filteredTag = '';
        if (preg_match('/\[([^\]]+)\]/', $processedDirName, $matches)) {
            $filteredTag = '[' . $matches[1] . ']';
        }
        
        return [$filteredTag, $dirType];
    }

    /**
     * 从文件名中提取标签（针对Drpy2目录的特殊逻辑）
     * @param string $fileName 文件名（不含后缀）
     * @return array [提取的标签数组, 过滤后的文件名]
     */
    function extractTagsFromFileName($fileName) {
        $tags = [];
        $processedName = $fileName;
        
        // 提取所有标签
        if (preg_match_all('/\[([^\]]+)\]/', $fileName, $matches)) {
            $tags = $matches[0]; // 获取所有匹配的标签，包括括号
            // 从文件名中移除所有标签
            foreach ($tags as $tag) {
                $processedName = str_replace($tag, '', $processedName);
            }
        }
        
        return [$tags, $processedName];
    }

    /**
     * 检查目录是否为特殊目录（XBPQ、XYQ、Drpy2）
     * @param string $dirName 目录名
     * @param bool $parentIsXBPQDir 父目录是否是XBPQ目录
     * @param bool $parentIsXYQDir 父目录是否是XYQ目录
     * @param bool $parentIsDrpy2Dir 父目录是否是Drpy2目录
     * @return array 返回[是否为XBPQ目录, 是否为XYQ目录, 是否为Drpy2目录]
     */
    function checkSpecialDirectory($dirName, $parentIsXBPQDir, $parentIsXYQDir, $parentIsDrpy2Dir) {
        // 先继承父目录的属性
        $isXBPQDir = $parentIsXBPQDir;
        $isXYQDir = $parentIsXYQDir;
        $isDrpy2Dir = $parentIsDrpy2Dir;
        
        // 如果父目录不是特殊目录，检查当前目录名
        if (!$isXBPQDir) {
            foreach (XBPQ_DIR_KEYWORDS as $keyword) {
                if (strpos($dirName, $keyword) !== false) {
                    $isXBPQDir = true;
                    break;
                }
            }
        }
        
        if (!$isXYQDir) {
            foreach (XYQ_DIR_KEYWORDS as $keyword) {
                if (strpos($dirName, $keyword) !== false) {
                    $isXYQDir = true;
                    break;
                }
            }
        }
        
        if (!$isDrpy2Dir) {
            foreach (DRPY2_DIR_KEYWORDS as $keyword) {
                if (strpos($dirName, $keyword) !== false) {
                    $isDrpy2Dir = true;
                    break;
                }
            }
        }
        
        return [$isXBPQDir, $isXYQDir, $isDrpy2Dir];
    }

    /**
     * 辅助：构建站点配置成员（取消全局配置，每种文件类型使用独立配置）
     */
    function buildSiteConfig($name, $filteredTag, $extInfo, $localPath, $phpApiPrefix, $dirType, $isXBPQDir = false, $isXYQDir = false, $isDrpy2Dir = false) {
        $ext = $extInfo['full'];
        $simpleExt = $extInfo['simple'];
        
        // 特殊处理：XBPQ目录下的json文件
        if ($isXBPQDir && $ext === 'json') {
            // 从文件名中提取所有标签
            list($tags, $pureName) = extractTagsFromFileName($name);
            
            // 检查是否包含[书]、[画]、[短剧]标签
            $hasBookTag = in_array('[书]', $tags);
            $hasComicTag = in_array('[画]', $tags);
            $hasDramaTag = in_array('[短剧]', $tags);
            
            // 根据是否有书画短剧标签决定站点名称格式
            if ($hasBookTag || $hasComicTag || $hasDramaTag) {
                // 有特殊标签：原始名称+后缀
                $fullName = $pureName . '(' . strtoupper($ext) . ')';
            } else {
                // 没有特殊标签：原始名称+目录标签+后缀
                $fullName = $pureName . $filteredTag . '(' . strtoupper($ext) . ')';
            }
            
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => 'csp_XBPQ',
                'jar' => './lib/XBPQ.jar',
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'changeable' => 1,
                'ext' => $localPath
            ];
            
            // 根据标签设置类型和lang
            if ($hasBookTag) {
                $config['类型'] = '小说';
                $config['lang'] = $simpleExt;
            } elseif ($hasComicTag) {
                $config['类型'] = '漫画';
                $config['lang'] = $simpleExt;
            } elseif ($hasDramaTag) {
                $config['类型'] = '短剧';
                $config['lang'] = $simpleExt;
            }
            
            return $config;
        }
        
        // 特殊处理：XYQ目录下的json文件
        if ($isXYQDir && $ext === 'json') {
            // 从文件名中提取所有标签
            list($tags, $pureName) = extractTagsFromFileName($name);
            
            // 检查是否包含[书]、[画]、[短剧]标签
            $hasBookTag = in_array('[书]', $tags);
            $hasComicTag = in_array('[画]', $tags);
            $hasDramaTag = in_array('[短剧]', $tags);
            
            // 根据是否有书画短剧标签决定站点名称格式
            if ($hasBookTag || $hasComicTag || $hasDramaTag) {
                // 有特殊标签：原始名称+后缀
                $fullName = $pureName . '(' . strtoupper($ext) . ')';
            } else {
                // 没有特殊标签：原始名称+目录标签+后缀
                $fullName = $pureName . $filteredTag . '(' . strtoupper($ext) . ')';
            }
            
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => 'csp_XYQHiker',
                'jar' => './lib/XYQ.jar',
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'changeable' => 1,
                'ext' => $localPath
            ];
            
            // 根据标签设置类型和lang
            if ($hasBookTag) {
                $config['类型'] = '小说';
                $config['lang'] = $simpleExt;
            } elseif ($hasComicTag) {
                $config['类型'] = '漫画';
                $config['lang'] = $simpleExt;
            } elseif ($hasDramaTag) {
                $config['类型'] = '短剧';
                $config['lang'] = $simpleExt;
            }
            
            return $config;
        }
        
        // 特殊处理：Drpy2目录下的js文件
        if ($isDrpy2Dir && $ext === 'js') {
            // 从文件名中提取所有标签
            list($tags, $pureName) = extractTagsFromFileName($name);
            
            // 检查是否包含[书]、[画]、[短剧]标签
            $hasBookTag = in_array('[书]', $tags);
            $hasComicTag = in_array('[画]', $tags);
            $hasDramaTag = in_array('[短剧]', $tags);
            
            // 根据是否有书画短剧标签决定站点名称格式
            if ($hasBookTag || $hasComicTag || $hasDramaTag) {
                // 有特殊标签：原始名称+后缀
                $fullName = $pureName . '(' . strtoupper($ext) . ')';
            } else {
                // 没有特殊标签：原始名称+目录标签+后缀
                $fullName = $pureName . $filteredTag . '(' . strtoupper($ext) . ')';
            }
            
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => './lib/lib/drpy2.min.js',
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'changeable' => 1,
                'ext' => $localPath
            ];
            
            // 根据标签设置类型和lang
            if ($hasBookTag) {
                $config['类型'] = '小说';
                $config['lang'] = $simpleExt;
            } elseif ($hasComicTag) {
                $config['类型'] = '漫画';
                $config['lang'] = $simpleExt;
            } elseif ($hasDramaTag) {
                $config['类型'] = '短剧';
                $config['lang'] = $simpleExt;
            }
            
            return $config;
        }
        
        // 非特殊目录下的文件，继续原有逻辑
        $fullName = $name . $filteredTag . '(' . strtoupper($ext) . ')';
        
        // 3. PHP文件
        if ($ext === 'php') {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 4,
                'searchable' => 1,
                'quickSearch' => 1,
                'changeable' => 1,
                'filterable' => 1
            ];
            // 特殊处理PHP文件的API路径
            $cleanPath = ltrim($localPath, './');
            $config['api'] = $phpApiPrefix . '/' . $cleanPath;
        }
        // 4. wv.js文件
        elseif ($ext === 'wv.js') {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => 'csp_WvSpider',
                'jar' => './lib/WvSpider.jar',
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'switchable' => 1,
                'ext' => $localPath
            ];
        }
        // 5. 纯文本文件 (txt, m3u, json)
        elseif (in_array($ext, ['txt', 'm3u', 'json']) || in_array($simpleExt, ['txt', 'm3u', 'json'])) {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => 'csp_FileSpider',
                'jar' => './lib/WvSpider.jar',
                'searchable' => 0,
                'quickSearch' => 0,
                'filterable' => 0,
                'switchable' => 0,
                'changeable' => 0,
                'ext' => $localPath
            ];
        }
        // 6. JS文件（普通目录下的js文件，不包括Drpy2目录）
        elseif ($ext === 'js' && !$isDrpy2Dir) {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => $localPath,
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'switchable' => 1
            ];
        }
        // 7. PY文件
        elseif ($ext === 'py') {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => $localPath,
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'switchable' => 1
            ];
        }
        // 8. 其他文件（按需扩展）
        else {
            $config = [
                'key' => $fullName,
                'name' => $fullName,
                'type' => 3,
                'api' => $localPath,
                'searchable' => 1,
                'quickSearch' => 1,
                'filterable' => 1,
                'switchable' => 1
            ];
        }
        
        // 根据目录类型添加特殊字段
        if ($dirType === 'book') {
            // 书目录：添加小说类型相关字段
            $config['title'] = $name;  // 小说名称
            $config['类型'] = '小说';
            $config['lang'] = $simpleExt;  // 文件扩展名作为语言标识
        } elseif ($dirType === 'comic') {
            // 漫画目录：添加漫画类型相关字段
            $config['title'] = $name;  // 漫画名称
            $config['类型'] = '漫画';
            $config['lang'] = $simpleExt;  // 文件扩展名作为语言标识
        }
        
        // 统一处理：如果目录类型是短剧且配置中还没有设置类型，则添加短剧类型
        if ($dirType === 'drama' && !isset($config['类型'])) {
            $config['类型'] = '短剧';
            $config['lang'] = $simpleExt;
        }
        
        return $config;
    }

    /**
     * 递归扫描目录函数 - 支持多层级目录结构
     * 修改：每个文件只使用其所在直接目录的标签，不继承父目录标签
     * 新增：支持XBPQ、XYQ、Drpy2目录的递归识别
     */
    function scanDirectoryRecursive($path, &$scannedSites, &$scannedLives, $currentDir, $allowedExtensions, $excludeDirs, $excludeFiles, $liveDirName, $hideSecretSites, $phpApiPrefix, $scanType = 'all', $currentDepth = 0, $maxDepth = MAX_SCAN_DEPTH, $parentIsXBPQDir = false, $parentIsXYQDir = false, $parentIsDrpy2Dir = false) {
        // 检查深度限制
        if ($currentDepth > $maxDepth) {
            return;
        }
        
        // 获取当前目录相对于脚本目录的路径
        $relativeDirPath = './' . str_replace([$currentDir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
        $pathParts = explode('/', ltrim($relativeDirPath, './'));
        
        $isRootDir = ($path === $currentDir);
        $isLiveDir = (count($pathParts) === 1 && $pathParts[0] === $liveDirName);
        
        // 获取当前目录名
        $currentDirName = basename($path);
        
        // 判断当前目录是否包含"[秘]"
        $isSecretDir = (strpos($currentDirName, '[秘]') !== false);
        
        // 检查当前目录是否为特殊目录（使用配置的关键词）
        list($isXBPQDir, $isXYQDir, $isDrpy2Dir) = checkSpecialDirectory($currentDirName, $parentIsXBPQDir, $parentIsXYQDir, $parentIsDrpy2Dir);
        
        // 检查目录类型并获取过滤后的标签
        list($filteredTag, $dirType) = checkDirTypeAndFilterTag($currentDirName);
        
        // 读取目录内容
        $items = scandir($path);
        
        foreach ($items as $item) {
            // 过滤排除目录
            if (in_array($item, $excludeDirs)) continue;
            
            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            $localPath = './' . str_replace([$currentDir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $fullPath);

            // 如果是目录且不是根目录，递归扫描
            if (is_dir($fullPath)) {
                // 直播目录下不再深入
                if ($isLiveDir) continue;
                
                // 递归扫描子目录（深度+1），传递特殊目录的继承属性
                scanDirectoryRecursive($fullPath, $scannedSites, $scannedLives, $currentDir, $allowedExtensions, $excludeDirs, $excludeFiles, $liveDirName, $hideSecretSites, $phpApiPrefix, $scanType, $currentDepth + 1, $maxDepth, $isXBPQDir, $isXYQDir, $isDrpy2Dir);
                continue;
            }

            // 处理文件
            // 不遍历 config.php 所在目录（根目录）下的文件
            if ($isRootDir) continue;

            // 过滤排除文件
            if (in_array($item, $excludeFiles)) continue;

            $extInfo = getFileExtensionInfo($item);
            $ext = $extInfo['full'];
            $simpleExt = $extInfo['simple'];

            // 检查文件后缀是否在允许列表中
            $isAllowedByExtension = in_array($ext, $allowedExtensions) || in_array($simpleExt, $allowedExtensions);
            
            // 特殊处理：根据扫描类型和目录类型决定是否处理文件
            if ($scanType === 'script') {
                // script模式：只允许脚本文件(js, wv.js, php, py)和XBPQ/XYQ/Drpy2目录下的文件
                if ($isXBPQDir || $isXYQDir || $isDrpy2Dir) {
                    // XBPQ、XYQ或Drpy2目录：允许所有文件（这些目录下的文件都是脚本相关）
                    // 继续处理
                } elseif (!$isAllowedByExtension) {
                    // 如果不是XBPQ/XYQ/Drpy2目录且不在允许的扩展名列表中，跳过
                    continue;
                }
            } elseif ($scanType === 'text') {
                // text模式：只允许纯文本文件(txt, m3u, json)，但要排除XBPQ/XYQ/Drpy2目录
                if ($isXBPQDir || $isXYQDir || $isDrpy2Dir) {
                    // XBPQ、XYQ或Drpy2目录：跳过所有文件（这些属于脚本目录）
                    continue;
                }
                
                if (!$isAllowedByExtension) {
                    // 不在允许的扩展名列表中，跳过
                    continue;
                }
            } else { // all模式
                // all模式：允许所有文件
                if (!$isAllowedByExtension) {
                    continue;
                }
            }

            // 解析文件名
            $displayName = $extInfo['name'];

            if ($isLiveDir) {
                // 直播文件 - 使用当前目录标签（过滤后的标签）
                $scannedLives[] = [
                    'name'       => $displayName . $filteredTag,
                    'type'       => 0,
                    'url'        => $localPath,
                    'playerType' => 2,
                    'epg'        => "http://epg.51zmt.top:8000/api/diyp/?ch={name}&date={date}",
                    'logo'       => "https://11.112114.xyz/logo/{$displayName}.png",
                    'ua'         => ''
                ];
            } else {
                // 站点文件：使用独立的文件类型配置
                $siteConfig = buildSiteConfig($displayName, $filteredTag, $extInfo, $localPath, $phpApiPrefix, $dirType, $isXBPQDir, $isXYQDir, $isDrpy2Dir);
                
                // 如果文件在带"[秘]"的目录中，根据开关设置hide字段
                if ($isSecretDir && $hideSecretSites) {
                    $siteConfig['hide'] = 1;
                }
                
                $scannedSites[] = $siteConfig;
            }
        }
    }

    // --------------------------------------------------------------------------
    // 4. 数据合并与结果输出
    // --------------------------------------------------------------------------

    // 1. 加载基准模版 config.json
    $baseConfigPath = BASE_TEMPLATE_FILE;
    $finalData = [];

    if (file_exists($baseConfigPath)) {
        $jsonContent = file_get_contents($baseConfigPath);
        $finalData = json_decode($jsonContent, true) ?: [];
        
        // 更新基准模板中的sites.api端口为最新获取的动态端口
        if (isset($finalData['sites']) && is_array($finalData['sites'])) {
            foreach ($finalData['sites'] as &$site) {
                if (isset($site['api']) && strpos($site['api'], 'http://127.0.0.1:') === 0) {
                    // 更新端口为最新检测到的端口
                    $site['api'] = preg_replace('/http:\/\/127\.0\.0\.1:\d+/', "http://127.0.0.1:{$phpPort}", $site['api']);
                }
            }
        }
    } else {
        echo "   ⚠️  基准模板文件不存在: " . $baseConfigPath . "\n";
    }

    // 2. 执行目录扫描逻辑
    scanDirectoryRecursive($currentDir, $scannedSites, $scannedLives, $currentDir, $allowedExtensions, $excludeDirs, $excludeFiles, $liveDirName, $hideSecretSites, $phpApiPrefix, $scanType);

    // 3. 追加扫描到的站点
    if (!isset($finalData['sites']) || !is_array($finalData['sites'])) {
        $finalData['sites'] = [];
    }
    $finalData['sites'] = array_merge($finalData['sites'], $scannedSites);

    // 4. 追加扫描到的直播
    if (!isset($finalData['lives']) || !is_array($finalData['lives'])) {
        $finalData['lives'] = [];
    }
    $finalData['lives'] = array_merge($finalData['lives'], $scannedLives);

    return $finalData;
}

// --------------------------------------------------------------------------
// 4. 核心功能类
// --------------------------------------------------------------------------

class NodeConfigSync {
    
    /**
     * 检查Node服务是否运行
     * @return bool 返回true表示服务运行正常
     */
    public static function checkNodeService() {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => HTTP_TIMEOUT,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents(NODE_SERVICE_URL, false, $context);
            
            // 检查HTTP响应码
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
                $httpCode = $matches[1] ?? null;
                
                // 只要能获取到HTTP响应码，就认为服务在运行
                return $httpCode !== null;
            }
            
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查本地配置文件是否存在
     * @param string $filePath 文件路径
     * @return bool 返回true表示文件存在
     */
    public static function checkLocalConfig($filePath = LOCAL_CONFIG_FILE) {
        return file_exists($filePath);
    }
    
    /**
     * 从Node服务获取完整配置
     * @return array 返回完整的配置数组
     * @throws Exception 获取或解析失败时抛出异常
     */
    public static function fetchRemoteFullConfig() {
        $context = stream_context_create([
            'http' => [
                'timeout' => HTTP_TIMEOUT
            ]
        ]);
        
        // 获取远程配置
        $json = @file_get_contents(NODE_CONFIG_API, false, $context);
        if ($json === false) {
            throw new Exception('无法从Node服务获取配置，请检查服务是否正常');
        }
        
        // 解析JSON
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Node服务返回的JSON格式无效: ' . json_last_error_msg());
        }
        
        // 检查是否是有效配置
        if (!is_array($data)) {
            throw new Exception('Node服务返回的数据格式不正确');
        }
        
        return $data;
    }
    
    /**
     * 从PHP接口获取配置
     * @return array 返回完整的配置数组
     * @throws Exception 获取或解析失败时抛出异常
     */
    public static function fetchPhpConfig() {
        // 动态检测PHP服务端口
        $phpPort = detectPhpPort();
        $phpConfigApi = "http://127.0.0.1:{$phpPort}/config.php";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => HTTP_TIMEOUT
            ]
        ]);
        
        echo "   从PHP接口获取配置: {$phpConfigApi}\n";
        
        // 获取远程配置
        $json = @file_get_contents($phpConfigApi, false, $context);
        if ($json === false) {
            throw new Exception('无法从PHP接口获取配置，请检查服务是否正常');
        }
        
        // 解析JSON
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('PHP接口返回的JSON格式无效: ' . json_last_error_msg());
        }
        
        // 检查是否是有效配置
        if (!is_array($data)) {
            throw new Exception('PHP接口返回的数据格式不正确');
        }
        
        return $data;
    }
    
    /**
     * 从Node服务获取配置（插入模式使用）
     * @param string $mode 模式：'insert' 或 'cover'，决定使用哪个过滤配置
     * @return array 返回过滤后的sites数组
     * @throws Exception 获取或解析失败时抛出异常
     */
    public static function fetchRemoteSites($mode = 'insert') {
        $context = stream_context_create([
            'http' => [
                'timeout' => HTTP_TIMEOUT
            ]
        ]);
        
        // 获取远程配置
        $json = @file_get_contents(NODE_CONFIG_API, false, $context);
        if ($json === false) {
            throw new Exception('无法从Node服务获取配置，请检查服务是否正常');
        }
        
        // 解析JSON
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Node服务返回的JSON格式无效: ' . json_last_error_msg());
        }
        
        // 检查sites字段
        if (!isset($data['sites']) || !is_array($data['sites'])) {
            throw new Exception('Node服务返回的数据中没有sites字段或格式不正确');
        }
        
        // 根据模式选择过滤配置
        if ($mode === 'cover') {
            $filterKeywords = COVER_MODE_FILTER;
        } else {
            $filterKeywords = INSERT_MODE_FILTER;
        }
        
        // 过滤站点
        $filteredSites = self::filterSites($data['sites'], $filterKeywords);
        
        return $filteredSites;
    }
    
    /**
     * 读取基准模板配置文件并更新端口
     * @return array 返回完整的配置数组（已更新端口）
     * @throws Exception 读取或解析失败时抛出异常
     */
    public static function readBaseTemplateFile() {
        if (!file_exists(BASE_TEMPLATE_FILE)) {
            throw new Exception('基准模板配置文件不存在: ' . BASE_TEMPLATE_FILE);
        }
        
        $json = @file_get_contents(BASE_TEMPLATE_FILE);
        if ($json === false) {
            throw new Exception('无法读取基准模板配置文件');
        }
        
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('基准模板配置文件JSON格式无效: ' . json_last_error_msg());
        }
        
        // 动态检测PHP服务端口
        $phpPort = detectPhpPort();
        
        // 更新基准模板中的sites.api端口为最新获取的动态端口
        if (isset($data['sites']) && is_array($data['sites'])) {
            foreach ($data['sites'] as &$site) {
                if (isset($site['api']) && strpos($site['api'], 'http://127.0.0.1:') === 0) {
                    // 更新端口为最新检测到的端口
                    $site['api'] = preg_replace('/http:\/\/127\.0\.0\.1:\d+/', "http://127.0.0.1:{$phpPort}", $site['api']);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * 过滤站点（排除包含特定关键词的站点）
     * @param array $sites 原始站点数组
     * @param array $filterKeywords 过滤关键词数组
     * @return array 过滤后的站点数组
     */
    public static function filterSites($sites, $filterKeywords) {
        $filtered = [];
        
        foreach ($sites as $site) {
            // 检查是否需要排除
            $shouldExclude = false;
            
            // 检查sites.api字段是否包含关键词
            if (isset($site['api']) && is_string($site['api'])) {
                foreach ($filterKeywords as $keyword) {
                    if (strpos($site['api'], $keyword) !== false) {
                        $shouldExclude = true;
                        break;
                    }
                }
            }
            
            if (!$shouldExclude) {
                $filtered[] = $site;
            }
        }
        
        return $filtered;
    }
    
    /**
     * 读取本地配置文件
     * @param string $filePath 文件路径
     * @return array 返回解析后的配置数组
     * @throws Exception 读取或解析失败时抛出异常
     */
    public static function readLocalConfig($filePath = LOCAL_CONFIG_FILE) {
        $json = @file_get_contents($filePath);
        if ($json === false) {
            throw new Exception('无法读取配置文件: ' . $filePath);
        }
        
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('配置文件JSON格式无效: ' . json_last_error_msg() . ' - ' . $filePath);
        }
        
        return $data;
    }
    
    /**
     * 从本地站点中提取需要保留的站点（覆盖模式使用）
     * @param array $localSites 本地站点数组
     * @return array 返回需要保留的站点数组
     */
    public static function extractKeepSites($localSites) {
        $keepSites = [];
        
        foreach ($localSites as $site) {
            $shouldKeep = false;
            
            // 检查sites.api字段是否包含需要保留的关键词
            if (isset($site['api']) && is_string($site['api'])) {
                foreach (COVER_KEEP_KEYWORDS as $keyword) {
                    if (strpos($site['api'], $keyword) !== false) {
                        $shouldKeep = true;
                        break;
                    }
                }
            }
            
            if ($shouldKeep) {
                $keepSites[] = $site;
            }
        }
        
        return $keepSites;
    }
    
    /**
     * 处理重复的key值，将后面的key按序加后缀重命名
     * @param array $sites 站点数组
     * @return array 返回去重后的站点数组
     */
    public static function deduplicateKeys($sites) {
        $keyCounts = [];
        $processedSites = [];
        
        foreach ($sites as $site) {
            if (!isset($site['key'])) {
                // 如果没有key字段，则跳过
                $processedSites[] = $site;
                continue;
            }
            
            $originalKey = $site['key'];
            $newKey = $originalKey;
            
            // 检查key是否已存在
            if (isset($keyCounts[$originalKey])) {
                // 如果已存在，开始添加后缀
                $counter = 1;
                $newKey = $originalKey . '_' . $counter;
                
                // 循环直到找到一个不重复的key
                while (isset($keyCounts[$newKey])) {
                    $counter++;
                    $newKey = $originalKey . '_' . $counter;
                }
                
                // 记录这个新key
                $keyCounts[$originalKey] = $counter;
                $site['key'] = $newKey;
                $site['name'] = isset($site['name']) ? $site['name'] . " ({$counter})" : $originalKey . " ({$counter})";
                
                // 提示信息
                echo "   发现重复key: '{$originalKey}'，已重命名为: '{$newKey}'\n";
            } else {
                // 首次出现，记录原始key
                $keyCounts[$originalKey] = 0;
            }
            
            // 记录新key
            $keyCounts[$newKey] = true;
            $processedSites[] = $site;
        }
        
        return $processedSites;
    }
    
    /**
     * 检查是否有重复的key值（用于验证去重结果）
     * @param array $sites 站点数组
     * @return bool 返回true表示无重复
     * @throws Exception 发现重复key时抛出异常
     */
    public static function verifyNoDuplicateKeys($sites) {
        $keys = [];
        
        foreach ($sites as $site) {
            if (isset($site['key'])) {
                $key = $site['key'];
                if (in_array($key, $keys)) {
                    throw new Exception("发现重复的key值: '{$key}'，去重逻辑失败");
                }
                $keys[] = $key;
            }
        }
        
        return true;
    }
    
    /**
     * 保存配置文件
     * @param array $config 配置数组
     * @param string $filePath 文件路径
     * @return bool 返回true表示保存成功
     * @throws Exception 保存失败时抛出异常
     */
    public static function saveConfig($config, $filePath = LOCAL_CONFIG_FILE) {
        // 格式化JSON
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('生成JSON时出错: ' . json_last_error_msg());
        }
        
        // 保存文件
        $result = @file_put_contents($filePath, $json);
        if ($result === false) {
            throw new Exception('保存配置文件失败: ' . $filePath);
        }
        
        return true;
    }
    
    /**
     * 只执行扫描模式，不插入道长源站
     * 修改：统一使用基准模板文件
     * 新增：支持扫描类型参数
     */
    public static function scanOnlyMode($scanType = 'all') {
        echo "📌 模式选择：只扫描模式\n";
        echo "   扫描类型: " . $scanType . "\n";
        echo "   基准模板: " . BASE_TEMPLATE_FILE . "\n";
        echo "   扫描层级限制: " . MAX_SCAN_DEPTH . " 层\n";
        echo str_repeat("-", 50) . "\n";
        
        // 0. 执行文件扫描生成本地配置
        echo "0. 执行文件扫描生成本地配置... \n";
        try {
            // 调用集成的config.php功能，使用统一基准模板和指定扫描类型
            $localConfig = generateLocalConfig($scanType);
            
            // 保存生成的配置到本地文件
            $json = json_encode($localConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents(LOCAL_CONFIG_FILE, $json);
            
            // 统计站点和直播数量
            $siteCount = isset($localConfig['sites']) ? count($localConfig['sites']) : 0;
            $liveCount = isset($localConfig['lives']) ? count($localConfig['lives']) : 0;
            
            echo "✅ 文件扫描完成，生成 {$siteCount} 个站点，{$liveCount} 个直播\n";
            echo "✅ 配置已保存到: " . LOCAL_CONFIG_FILE . "\n";
        } catch (Exception $e) {
            throw new Exception("文件扫描失败: " . $e->getMessage());
        }
        
        echo str_repeat("-", 50) . "\n";
        echo "🎉 扫描完成！\n";
    }
    
    /**
     * 插入模式（方案1）
     * 修改：不再调用外部config.php服务，而是集成其功能
     * 修改：道长源站插入到生成的config.json内sites站点的最后
     * @param bool $doScan 是否执行扫描步骤
     */
    public static function insertMode($doScan = true) {
        echo "📌 模式选择：插入模式\n";
        echo "   过滤关键词: " . implode(', ', INSERT_MODE_FILTER) . "\n";
        echo "   基准模板: " . BASE_TEMPLATE_FILE . "\n";
        echo "   扫描层级限制: " . MAX_SCAN_DEPTH . " 层\n";
        if (!$doScan) {
            echo "   ⚠️  跳过扫描步骤\n";
        }
        echo str_repeat("-", 50) . "\n";
        
        // 0. 执行文件扫描生成本地配置（集成版）
        if ($doScan) {
            echo "0. 执行文件扫描生成本地配置... \n";
            try {
                // 调用集成的config.php功能，使用统一基准模板，固定扫描脚本文件
                $localConfig = generateLocalConfig('script');
                
                // 保存生成的配置到本地文件
                $json = json_encode($localConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                file_put_contents(LOCAL_CONFIG_FILE, $json);
                
                // 统计站点和直播数量
                $siteCount = isset($localConfig['sites']) ? count($localConfig['sites']) : 0;
                $liveCount = isset($localConfig['lives']) ? count($localConfig['lives']) : 0;
                
                echo "✅ 文件扫描完成，生成 {$siteCount} 个站点，{$liveCount} 个直播\n";
            } catch (Exception $e) {
                throw new Exception("文件扫描失败，无法生成本地配置: " . $e->getMessage());
            }
        } else {
            echo "0. 跳过扫描步骤，使用现有配置文件\n";
        }
        
        echo str_repeat("-", 50) . "\n";
        
        // 1. 检查Node服务
        echo "1. 检查Node服务... ";
        if (!self::checkNodeService()) {
            throw new Exception("Node服务未运行\n   请确保 node index.js 服务在 " . NODE_SERVICE_URL . " 端口正常运行");
        }
        echo "✅ 服务正常运行\n";
        
        // 2. 获取远程配置并过滤
        echo "2. 从Node服务获取配置... ";
        $remoteSites = self::fetchRemoteSites('insert');
        $remoteCount = count($remoteSites);
        echo "✅ 获取成功，共 {$remoteCount} 个站点\n";
        
        if ($remoteCount === 0) {
            echo "⚠️  没有需要插入的站点，程序结束\n";
            return;
        }
        
        // 3. 读取本地配置
        echo "3. 读取本地配置文件... ";
        $localConfig = self::readLocalConfig();
        
        if (!isset($localConfig['sites']) || !is_array($localConfig['sites'])) {
            throw new Exception('本地配置文件缺少sites字段或格式不正确');
        }
        
        $localCount = count($localConfig['sites']);
        echo "✅ 读取成功，共 {$localCount} 个站点\n";
        
        // 4. 将道长源站插入到sites数组的最后（修改点）
        echo "4. 将道长源站插入到本地配置末尾... ";
        // 直接将远程站点合并到本地站点数组的末尾
        $newSites = array_merge($localConfig['sites'], $remoteSites);
        $newCount = count($newSites);
        echo "✅ 插入完成，现在共 {$newCount} 个站点\n";
        
        // 5. 处理重复key（按序加后缀重命名）
        echo "5. 处理重复key... ";
        $newSites = self::deduplicateKeys($newSites);
        echo "✅ 重复key处理完成\n";
        
        // 6. 验证去重结果
        echo "6. 验证去重结果... ";
        self::verifyNoDuplicateKeys($newSites);
        echo "✅ 无重复key\n";
        
        // 7. 更新配置并保存
        echo "7. 保存配置文件... ";
        $localConfig['sites'] = $newSites;
        self::saveConfig($localConfig);
        echo "✅ 保存成功\n";
        
        // 输出统计信息
        echo str_repeat("-", 50) . "\n";
        echo "📊 同步统计信息：\n";
        if ($doScan) {
            echo "   扫描本地站点数: {$localCount}\n";
        } else {
            echo "   原本地站点数: {$localCount}\n";
        }
        echo "   新增道长站点数: {$remoteCount}\n";
        echo "   总站点数: " . count($newSites) . "\n";
        echo "   扫描层级限制: " . MAX_SCAN_DEPTH . " 层\n";
        echo "   插入位置: sites数组末尾\n";
        echo "   配置文件: " . LOCAL_CONFIG_FILE . "\n";
    }
    
    /**
     * 覆盖模式（方案2）
     * 修改：从Node服务获取配置，完全覆盖基准模板文件，但保留指定关键词的站点
     */
    public static function coverMode() {
        echo "📌 模式选择：覆盖模式\n";
        echo "   从Node服务获取配置: " . NODE_SERVICE_URL . "\n";
        echo "   基准模板: " . BASE_TEMPLATE_FILE . "\n";
        echo "   过滤关键词: " . implode(', ', COVER_MODE_FILTER) . "\n";
        echo "   保留关键词: " . implode(', ', COVER_KEEP_KEYWORDS) . "\n";
        echo str_repeat("-", 50) . "\n";
        echo "⚠️  注意：此模式将从Node服务获取配置，完全覆盖基准模板文件\n";
        echo "   但保留以下关键词的站点：\n";
        foreach (COVER_KEEP_KEYWORDS as $keyword) {
            echo "     - {$keyword}\n";
        }
        echo str_repeat("-", 50) . "\n";
        
        // 1. 检查Node服务是否运行
        echo "1. 检查Node服务... ";
        if (!self::checkNodeService()) {
            throw new Exception("Node服务未运行\n   请确保 node index.js 服务在 " . NODE_SERVICE_URL . " 端口正常运行");
        }
        echo "✅ 服务正常运行\n";
        
        // 2. 从Node服务获取完整配置
        echo "2. 从Node服务获取完整配置... ";
        $remoteConfig = self::fetchRemoteFullConfig();
        
        if (!isset($remoteConfig['sites']) || !is_array($remoteConfig['sites'])) {
            throw new Exception('Node服务返回的数据中没有sites字段或格式不正确');
        }
        
        $remoteSiteCount = count($remoteConfig['sites']);
        echo "✅ 获取成功，共 {$remoteSiteCount} 个站点\n";
        
        // 3. 读取基准模板配置文件
        echo "3. 读取基准模板配置文件... ";
        try {
            $baseConfig = self::readBaseTemplateFile();
            if (isset($baseConfig['sites']) && is_array($baseConfig['sites'])) {
                $keepSites = self::extractKeepSites($baseConfig['sites']);
                $keepCount = count($keepSites);
                echo "✅ 找到 {$keepCount} 个需要保留的站点\n";
            } else {
                echo "⚠️  基准模板配置文件缺少sites字段\n";
                $keepSites = [];
            }
        } catch (Exception $e) {
            echo "⚠️  读取基准模板配置文件失败: " . $e->getMessage() . "\n";
            $keepSites = [];
        }
        
        // 4. 执行覆盖逻辑（过滤远程配置中的道长站点，并保留特定站点）
        echo "4. 执行覆盖逻辑... \n";
        
        // 过滤远程配置的sites（排除道长特定站点）
        $remoteConfig['sites'] = self::filterSites($remoteConfig['sites'], COVER_MODE_FILTER);
        
        // 新增逻辑：对过滤后的远程站点，检查title是否包含"短剧"关键词，并设置类型为"短剧"
        foreach ($remoteConfig['sites'] as &$site) {
            if (isset($site['title']) && strpos($site['title'], '短剧') !== false) {
                $site['类型'] = '短剧';
            }
        }
        unset($site); // 解除引用
        // 新增逻辑：对过滤后的远程站点，检查title是否包含短剧关键词，并设置类型为"短剧"
        foreach ($remoteConfig['sites'] as &$site) {
            if (isset($site['title']) && strpos($site['title'], DRAMA_KEYWORD) !== false) {
                $site['类型'] = '短剧';
            }
        }
        unset($site); // 解除引用
        
        // 如果有需要保留的站点，将它们合并到远程配置的sites数组中
        if (!empty($keepSites)) {
            // 将保留站点合并到远程站点数组的开头（优先显示）
            $remoteConfig['sites'] = array_merge($keepSites, $remoteConfig['sites']);
        }
        
        // 5. 处理重复key（按序加后缀重命名）
        echo "5. 处理重复key... ";
        if (isset($remoteConfig['sites']) && is_array($remoteConfig['sites'])) {
            $remoteConfig['sites'] = self::deduplicateKeys($remoteConfig['sites']);
            echo "✅ 重复key处理完成\n";
        } else {
            echo "⚠️  配置中没有sites字段，跳过重复key处理\n";
        }
        
        // 6. 验证去重结果
        echo "6. 验证去重结果... ";
        if (isset($remoteConfig['sites']) && is_array($remoteConfig['sites'])) {
            self::verifyNoDuplicateKeys($remoteConfig['sites']);
            echo "✅ 无重复key\n";
        } else {
            echo "⚠️  配置中没有sites字段，跳过验证\n";
        }
        
        // 7. 保存到脚本目录下的config.json（覆盖现有文件）
        echo "7. 保存配置文件到脚本目录... ";
        self::saveConfig($remoteConfig, LOCAL_CONFIG_FILE);
        echo "✅ 保存成功\n";
        
        // 输出统计信息
        $finalSiteCount = isset($remoteConfig['sites']) ? count($remoteConfig['sites']) : 0;
        
        echo str_repeat("-", 50) . "\n";
        echo "📊 同步统计信息：\n";
        echo "   Node服务站点数: {$remoteSiteCount}\n";
        echo "   保留基准模板站点数: " . count($keepSites) . "\n";
        echo "   最终站点数: {$finalSiteCount}\n";
        echo "   配置文件: " . LOCAL_CONFIG_FILE . "\n";
    }
    
    /**
     * 模板模式（新增）
     * 从PHP接口获取配置，以基准模板文件为基础，保留指定关键词的站点，替换本地配置文件
     */
    public static function templateMode() {
        echo "📌 模式选择：模板模式\n";
        echo "   从PHP接口获取配置\n";
        echo "   基准模板: " . BASE_TEMPLATE_FILE . "\n";
        echo "   保留关键词: " . implode(', ', COVER_KEEP_KEYWORDS) . "\n";
        echo str_repeat("-", 50) . "\n";
        echo "⚠️  注意：此模式将从PHP接口获取配置，替换基准模板文件配置\n";
        echo "   但保留以下关键词的站点：\n";
        foreach (COVER_KEEP_KEYWORDS as $keyword) {
            echo "     - {$keyword}\n";
        }
        echo str_repeat("-", 50) . "\n";
        
        // 1. 从PHP接口获取配置
        echo "1. 从PHP接口获取配置... ";
        try {
            $phpConfig = self::fetchPhpConfig();
            $phpSiteCount = isset($phpConfig['sites']) ? count($phpConfig['sites']) : 0;
            $phpLiveCount = isset($phpConfig['lives']) ? count($phpConfig['lives']) : 0;
            echo "✅ 获取成功，共 {$phpSiteCount} 个站点，{$phpLiveCount} 个直播\n";
        } catch (Exception $e) {
            throw new Exception("从PHP接口获取配置失败: " . $e->getMessage());
        }
        
        // 2. 读取基准模板配置文件
        echo "2. 读取基准模板配置文件... ";
        try {
            $baseConfig = self::readBaseTemplateFile();
            if (isset($baseConfig['sites']) && is_array($baseConfig['sites'])) {
                $keepSites = self::extractKeepSites($baseConfig['sites']);
                $keepCount = count($keepSites);
                echo "✅ 找到 {$keepCount} 个需要保留的站点\n";
            } else {
                echo "⚠️  基准模板配置文件缺少sites字段\n";
                $keepSites = [];
            }
        } catch (Exception $e) {
            echo "⚠️  读取基准模板配置文件失败: " . $e->getMessage() . "\n";
            $keepSites = [];
        }
        
        // 3. 执行替换逻辑（以PHP配置为基础，保留特定站点）
        echo "3. 执行替换逻辑... \n";
        
        // 以PHP配置为基础
        $finalConfig = $phpConfig;
        
        // 如果有需要保留的站点，将它们合并到PHP配置的sites数组中
        if (!empty($keepSites)) {
            // 将保留站点合并到PHP站点数组的开头（优先显示）
            $finalConfig['sites'] = array_merge($keepSites, $phpConfig['sites']);
        }
        
        // 4. 处理重复key（按序加后缀重命名）
        echo "4. 处理重复key... ";
        if (isset($finalConfig['sites']) && is_array($finalConfig['sites'])) {
            $finalConfig['sites'] = self::deduplicateKeys($finalConfig['sites']);
            echo "✅ 重复key处理完成\n";
        } else {
            echo "⚠️  配置中没有sites字段，跳过重复key处理\n";
        }
        
        // 5. 验证去重结果
        echo "5. 验证去重结果... ";
        if (isset($finalConfig['sites']) && is_array($finalConfig['sites'])) {
            self::verifyNoDuplicateKeys($finalConfig['sites']);
            echo "✅ 无重复key\n";
        } else {
            echo "⚠️  配置中没有sites字段，跳过验证\n";
        }
        
        // 6. 保存到脚本目录下的config.json（覆盖现有文件）
        echo "6. 保存配置文件到脚本目录... ";
        self::saveConfig($finalConfig, LOCAL_CONFIG_FILE);
        echo "✅ 保存成功\n";
        
        // 输出统计信息
        $finalSiteCount = isset($finalConfig['sites']) ? count($finalConfig['sites']) : 0;
        $finalLiveCount = isset($finalConfig['lives']) ? count($finalConfig['lives']) : 0;
        
        echo str_repeat("-", 50) . "\n";
        echo "📊 同步统计信息：\n";
        echo "   PHP接口站点数: {$phpSiteCount}\n";
        echo "   PHP接口直播数: {$phpLiveCount}\n";
        echo "   保留基准模板站点数: " . count($keepSites) . "\n";
        echo "   最终站点数: {$finalSiteCount}\n";
        echo "   最终直播数: {$finalLiveCount}\n";
        echo "   配置文件: " . LOCAL_CONFIG_FILE . "\n";
    }
    
    /**
     * 主执行函数
     * @param string $mode 执行模式：'insert' 或 'cover' 或 'scan-only' 或 'template'
     * @param array $options 选项数组，包含 'no-scan' 等
     */
    public static function run($mode = 'insert', $options = []) {
        echo "🚀 开始执行Node服务配置同步...\n";
        
        try {
            // 根据参数选择执行模式
            if ($mode === 'cover') {
                self::coverMode();
            } elseif ($mode === 'scan-only') {
                // 获取扫描类型参数
                $scanType = isset($options['scan-type']) ? $options['scan-type'] : 'all';
                self::scanOnlyMode($scanType);
            } elseif ($mode === 'template') {
                self::templateMode();
            } else {
                // 插入模式，根据选项决定是否扫描
                $doScan = !isset($options['no-scan']) || $options['no-scan'] !== true;
                self::insertMode($doScan);
            }
            
            echo str_repeat("-", 50) . "\n";
            echo "🎉 同步完成！\n";
            
        } catch (Exception $e) {
            echo "❌ 错误: " . $e->getMessage() . "\n";
            echo str_repeat("-", 50) . "\n";
            echo "💡 建议：\n";
            
            if ($mode === 'insert') {
                echo "   1. 确保脚本目录结构正确\n";
                echo "   2. 检查Node服务是否正常运行\n";
                echo "   3. 确认配置文件格式正确\n";
            } elseif ($mode === 'cover') {
                echo "   1. 检查Node服务是否正常运行: " . NODE_SERVICE_URL . "\n";
                echo "   2. 确认基准模板文件是否存在: " . BASE_TEMPLATE_FILE . "\n";
                echo "   3. 确认配置文件格式正确\n";
            } elseif ($mode === 'scan-only') {
                echo "   1. 确保脚本目录结构正确\n";
                echo "   2. 检查基准模板文件是否存在: " . BASE_TEMPLATE_FILE . "\n";
                echo "   3. 确认有文件扫描权限\n";
                echo "   4. 确认扫描类型参数正确 (text, script, all)\n";
            } elseif ($mode === 'template') {
                echo "   1. 检查PHP服务是否正常运行\n";
                echo "   2. 确认基准模板文件是否存在: " . BASE_TEMPLATE_FILE . "\n";
                echo "   3. 确认PHP接口/config.php可访问\n";
            }
            
            exit(1);
        }
    }
}

// --------------------------------------------------------------------------
// 5. 脚本执行入口
// --------------------------------------------------------------------------

// 设置时区（避免时间相关错误）
date_default_timezone_set('Asia/Shanghai');

// 设置错误显示
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 检查是否在CLI模式下运行
if (php_sapi_name() === 'cli') {
    // CLI模式运行，支持参数选择模式
    $mode = 'insert'; // 默认模式
    $options = [];
    
    // 解析命令行参数
    if (isset($argv[1])) {
        $arg = strtolower($argv[1]);
        if ($arg === 'cover') {
            $mode = 'cover';
        } elseif ($arg === 'insert') {
            $mode = 'insert';
        } elseif ($arg === 'scan-only') {
            $mode = 'scan-only';
            // 检查是否有扫描类型参数
            if (isset($argv[2]) && in_array($argv[2], ['text', 'script', 'all'])) {
                $options['scan-type'] = $argv[2];
            } else {
                // 默认扫描所有类型
                $options['scan-type'] = 'all';
            }
        } elseif ($arg === 'template') {
            $mode = 'template';
        } else {
            echo "❌ 错误：未知模式 '{$argv[1]}'\n";
            echo "💡 用法：\n";
            echo "   插入模式（默认）：php " . basename(__FILE__) . " insert\n";
            echo "   插入模式（跳过扫描）：php " . basename(__FILE__) . " insert --no-scan\n";
            echo "   覆盖模式：php " . basename(__FILE__) . " cover\n";
            echo "   只扫描模式（文本文件）：php " . basename(__FILE__) . " scan-only text\n";
            echo "   只扫描模式（脚本文件）：php " . basename(__FILE__) . " scan-only script\n";
            echo "   只扫描模式（所有文件）：php " . basename(__FILE__) . " scan-only all\n";
            echo "   模板模式：php " . basename(__FILE__) . " template\n";
            exit(1);
        }
        
        // 检查是否有--no-scan选项
        if ($mode === 'insert' && isset($argv[2]) && $argv[2] == '--no-scan') {
            $options['no-scan'] = true;
        } elseif ($mode !== 'insert' && isset($argv[2]) && $argv[2] == '--no-scan') {
            echo "⚠️  警告：--no-scan 选项只对插入模式有效\n";
        }
    }
    
    NodeConfigSync::run($mode, $options);
} else {
    // Web模式运行（HTTP访问）
    header('Content-Type: text/plain; charset=utf-8');
    echo "Node服务配置同步脚本\n";
    echo "开始时间: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat("=", 60) . "\n";
    
    // Web模式下默认使用插入模式
    $mode = 'insert';
    $options = [];
    
    // 尝试从GET参数获取模式
    if (isset($_GET['mode'])) {
        $arg = strtolower($_GET['mode']);
        if ($arg === 'cover') {
            $mode = 'cover';
        } elseif ($arg === 'scan-only') {
            $mode = 'scan-only';
            // 获取扫描类型参数
            if (isset($_GET['type']) && in_array($_GET['type'], ['text', 'script', 'all'])) {
                $options['scan-type'] = $_GET['type'];
            } else {
                $options['scan-type'] = 'all';
            }
        } elseif ($arg === 'template') {
            $mode = 'template';
        }
    }
    
    // 检查是否有no-scan参数
    if (isset($_GET['no-scan']) && $_GET['no-scan'] == '1') {
        $options['no-scan'] = true;
    }
    
    // 执行同步
    ob_start();
    NodeConfigSync::run($mode, $options);
    $output = ob_get_clean();
    
    // 输出结果
    echo $output;
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "结束时间: " . date('Y-m-d H:i:s') . "\n";
}