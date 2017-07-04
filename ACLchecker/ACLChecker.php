<?php

/**
 * ACL节点信息
 * Class ACL
 */
class ACLChecker {

    const MD_SIMPLE = 1; //不解析注释
    const MD_DETAILED = 2; //解析注释
    const MD_SIMPLE_WITH_NAME = 3; //只解析注释中名称

    /**
     * conf
     */
    public $rootDir = '/'; //根目录
    public $onlyDirs = []; //只看目录
    public $ignoreDirs = []; //忽略目录
    public $ignoreClasses = []; //忽略类
    public $validParams = []; //注释中合法的参数名
    public $mode = self::MD_DETAILED; //模式
    public $fileNameRules = "/Controller/"; //文件名匹配规则
    public $methodNameRules = "/./";//方法名匹配规则

    public $enableNamespace = true; //启用命名空间
    public $classFormatter; //类名转换
    public $methodFormatter; //方法名转换

    /**
     * comment regx
     */
    public $keyPattern = "[A-z0-9\_\-]+"; //参数k&v匹配
    public $endPattern = "[ ]*(?:@|\r\n|\n)"; //参数信息结束匹配;
    public $namePattern = "/./"; //注释名称匹配

    /**
     * data
     */
    public $dirs = []; //目录数组
    public $files = []; //文件数组
    public $nameSpaces = []; //命名空间数组
    public $classes = []; //类信息
    public $methods = []; //方法信息

    public $NoCommentClasses = []; //无注释类
    public $NoCommentMethods = []; //无注释方法

    public $invalidFiles = []; //无法获得类信息的文件

    /**
     * others
     */
    public $logFileName; //日志文件名
    public $logDir; //日志路径


    /**
     * 构造函数
     * ACL constructor.
     * @param array $config
     */
    public function __construct($config = []){
        foreach ($config as $key => $value){
            if(property_exists(self::class,$key))
                $this->$key = $value;
        }
        $this->rootDir = realpath($this->rootDir);
        $this->ignoreClasses[] = get_class($this); //排除
        if(!is_callable($this->classFormatter)){
            $this->classFormatter = function ($str){
                return $str;
            }; //默认
        }
        if(!is_callable($this->methodFormatter)){
            $this->methodFormatter = function ($str){
                return $str;
            }; //默认
        }
    }

    /**
     *  扫描目录载入数据
     */
    public function load(){
        //获取目录和文件信息
        if(!empty($this->onlyDirs)){
            foreach ($this->onlyDirs as $dir) {
                $this->getFiles($dir);
            }
        }else{
            $this->getFiles($this->rootDir); //获取将要扫描的目录和文件
        }
        //遍历文件并解析类
        $this->analyzeFiles();
    }

    /**
     *  获取结构化节点数据
     */
    public function getStructureData(){
        $data = [];
        foreach ($this->nameSpaces as $nameSpace) {
            $one = ['namespace' => $nameSpace,'classes' => []];
            foreach ($this->classes as $full => $class) {
                if($class['nameSpace'] == $nameSpace){
                    $oneClass = [
                        'id' => call_user_func($this->classFormatter,$class['id']),
                        'methods' => []
                    ];
                    if(isset($class['name'])){
                        $oneClass['name'] = $class['name'];
                    }
                    if(isset($class['params'])){
                        $oneClass['params'] = $class['params'];
                    }
                    foreach ($class['method'] as $m) {
                        if(isset($this->methods[$full."-".$m])){
                            $method = $this->methods[$full."-".$m];
                            $oneMethod = [
                                'id' => call_user_func($this->methodFormatter,$method['id']),
                            ];
                            if(isset($method['name'])){
                                $oneMethod['name'] = $method['name'];
                            }
                            if(isset($method['params'])){
                                $oneMethod['params'] = $method['params'];
                            }
                            $oneClass['methods'][] = $oneMethod;
                        }
                    }
                    $one['classes'][] = $oneClass;
                }
            }
            $data[] = $one;
        }
        return $data;
    }

    /**
     * 扫描文件
     */
    private function getFiles($dir){
        if(is_dir($dir))
        {
            $this->dirs[] = $dir;
            if($handle=opendir($dir))
            {
                while(($file = readdir($handle)) !== false)
                {
                    if($file!="." && $file!=".." && !in_array(realpath($dir."\\".$file),$this->ignoreDirs))
                    {
                        if(is_dir($dir."\\".$file))
                        {
                            $this->getFiles($dir."/".$file);
                        }
                        else
                        {
                            if((!$this->fileNameRules || preg_match($this->fileNameRules, $file)) && preg_match("/\.php/", $file)){
                                $this->files[] = $dir . "\\" . $file;
                            }
                        }
                    }
                }
                closedir($handle);
            }
        }
    }

    private function analyzeFiles(){
        foreach ($this->files as $file) {
            //载入
            include_once $file;
            $className = trim(basename($file,".php"));
            //获取命名空间(暂从路径获取)
            $path = pathinfo($file)['dirname'];
            $nameSpace = str_replace(realpath($this->rootDir),'',realpath($path));
            if(!in_array($nameSpace,$this->nameSpaces)){
                $this->nameSpaces[] = $nameSpace;
            }
            try {
                $class = new \ReflectionClass($nameSpace . "\\" .$className);
            } catch (\ReflectionException $e) {
                try {
                    $class = new \ReflectionClass($className);
                } catch (Exception $e) {
                    $this->invalidFiles[] = $file;
                    continue;
                }
            }
            if(!empty($this->ignoreClasses) && in_array($nameSpace . "\\" .$className, $this->ignoreClasses)){
                continue;
            }
            $baseInfo = ['id' => $className , 'file' => $file, 'nameSpace' => $nameSpace, 'method' => []];
            //注释解析
            $docComment = $class->getDocComment();
            if(!$docComment) $this->NoCommentClasses[] = $className;
            $docInfo = $this->parse($docComment);
            $this->classes[$nameSpace . "\\" .$className] = array_merge($baseInfo,$docInfo);
            //获取类方法(接口)
            $methods = $class->getMethods();
            foreach ($methods as $method) {
                if ($method->isPublic() && !$method->isConstructor() && preg_match($this->methodNameRules, $method->name)) {
                    $this->classes[$nameSpace . "\\" .$className]['method'][] = $method->name;
                    $key = $nameSpace . "\\" .$className . '-' . $method->name;
                    $baseInfo = ['id' => $method->name , 'class' => $nameSpace . "\\" .$className];
                    //注释解析
                    $docComment = $method->getDocComment();
                    if(!$docComment) $this->NoCommentMethods[] = $key;
                    $docInfo = $this->parse($docComment);
                    $this->methods[$key] = array_merge($baseInfo,$docInfo);
                }
            }
        }
    }

    /**
     * 名称解析
     * @param $rawDocBlock
     * @return string
     */
    private function getDocName($rawDocBlock){
        if(!$rawDocBlock) return '';
        $parts = explode("\n", $rawDocBlock);
        if (isset($parts[1]) && preg_match($this->namePattern, $parts[1])) {
            return trim(trim(trim($parts[1]),"*"));
        }
        return '';
    }

    /**
     * 注释解析
     * @param $rawDocBlock
     */
    private function getDocParams($rawDocBlock){
        if(!$rawDocBlock) return [];
        $params = [];
        $pattern = "/@(?=(.*)".$this->endPattern.")/U";
        preg_match_all($pattern,$rawDocBlock, $matches);
        foreach($matches[1] as $rawParameter)
        {
            if(preg_match("/^(".$this->keyPattern.") +(.*)$/", $rawParameter, $match))
            {
                if(isset($params[$match[1]]))
                {
                    $params[$match[1]] = array_merge((array)$params[$match[1]], (array)$match[2]);
                }
                else
                {
                    $params[$match[1]] = $this->parseValue($match[2]);
                }
            }
            else if(preg_match("/^".$this->keyPattern."$/", $rawParameter, $match))
            {
                $params[$rawParameter] = TRUE;
            }
            else
            {
                $params[$rawParameter] = NULL;
            }
        }
        //过滤参数
        if(!empty($this->validParams)){
            foreach (array_diff(array_keys($params),$this->validParams) as $v){
                unset($params[$v]);
            }
        }

        return $params;
    }

    /**
     *   解析
     */
    private function parse($rawDocBlock){
        $data = [];
        switch ($this->mode) {
            case self::MD_SIMPLE:
                break;
            case self::MD_SIMPLE_WITH_NAME:
                $data['name'] = $this->getDocName($rawDocBlock);
                break;
            case self::MD_DETAILED:
                $data['name'] = $this->getDocName($rawDocBlock);
                $data['params'] = $this->getDocParams($rawDocBlock);
                break;
            default:
                break;
        }
        return $data;
    }

    /**
     *  convert
     */
    private function parseValue($originalValue)
    {
        if($originalValue && $originalValue !== 'null')
        {
            if( ($json = json_decode($originalValue,TRUE)) === NULL)
            {
                //逗号分隔
                $value = trim($originalValue);
            }
            else
            {
                $value = $json;
            }
        }
        else
        {
            $value = NULL;
        }
        return $value;
    }

    /**
     *  记录日志
     */
    public function log(){
        //TODO:
    }
}