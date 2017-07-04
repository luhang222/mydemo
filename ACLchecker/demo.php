<?php
require_once "ACLChecker.php";

$config = [
    'rootDir' => '', //项目根目录
    'onlyDirs' => [
        realpath('controllers'),
    ],
    'ignoreDirs' => [], //忽略的路径
    'ignoreClasses' => ['\backend\controllers\PublicController','\backend\controllers\BaseController'], //忽略的类
    'mode' => ACLChecker::MD_DETAILED,
    'validParams' => ['ignore','public','associate'], //忽略节点、关联节点
    'fileNameRules' => "/Controller/",
    'methodNameRules' => "/action[A-Z]/",
    'classFormatter' => function($name){
        $name = lcfirst(explode('Controller',$name)[0]);
        return strtolower(preg_replace('/((?<=[a-z])(?=[A-Z]))/', '-', $name));
    },
    'methodFormatter' => function($name){
        $str = lcfirst(explode('action',$name)[1]);
        return strtolower(preg_replace('/((?<=[a-z])(?=[A-Z]))/', '-', $str));
    }
];

$acl = new ACLChecker($config);
//载入节点数据
$acl->load();
$this->showProcess("finished!");
$acl->getStructureData();

?>