<?php
namespace yangsl\apidoc\apidoc;

defined('D_S') || define('D_S', DIRECTORY_SEPARATOR);
define('API_ROOT', '.');

class ApiList {
    /**
     * @var bool 是否检测基础控制器
     */
    public $appControllers = true;

    /**
     * 需要生成文档的模块名
     * @param array $modules
     */
    public function getApiList($modules = []) {
        $allApiS = [];
        $projectName = 'yii-doc-online'; //todo
        // 主题风格，fold = 折叠，expand = 展开
        $theme = \Yii::$app->request->get('type', 'fold');
        if (!in_array($theme, array('fold', 'expand'))) {
            $theme = 'fold';
        }
        $appControllers = [];
        $apiDirName = $this->appControllers?'../controllers':'';
        // 处理最外层的控制器 \app\controllers
        if ($this->appControllers) {
            $files = listDir(API_ROOT . D_S . $apiDirName);
            $appControllers = array_map(function($file){
                $classNameTemp = strstr($file, '/controllers');
                $classNameTemp = rtrim(substr($classNameTemp, 13), '.php');
                $className = \Yii::$app->controllerNamespace . '\\'. $classNameTemp;
                return $className;
            }, $files);
        }
        //遍历module下的所有控制器
        $modulesClassesNameTemp = array_map(function($module){
            $t = new \ReflectionClass($module);
            $moduleNamespace = $t->getNamespaceName();
            $moduleDirName = $t->getFileName();
            $moduleDir = rtrim($moduleDirName, 'Module.php');
            $moduleDir = $moduleDir. 'controllers';
            $moduleFiles = listDir($moduleDir);
            return array_map(function($moduleFile) use ($moduleNamespace) {
                $namespace = $moduleNamespace . '\\controllers\\%s';
                $className = rtrim(substr($moduleFile, strrpos($moduleFile, D_S) + 1), '.php');
                return sprintf($namespace, $className);
            }, $moduleFiles);
        }, $modules);

        $modulesControllers = [];
        foreach ($modulesClassesNameTemp as $moduleControllers) {
            $modulesControllers = array_merge($modulesControllers, $moduleControllers);
        }

        $apiControllers = array_merge($appControllers, $modulesControllers);
        foreach ($apiControllers as $k=>$className) {
            if (substr($className, -10) != 'Controller') {
                unset($apiControllers[$k]);
            }
        }


        foreach ($apiControllers as $ctlClassName) {
            $route = str_replace('\\', '/', $ctlClassName);
            $route = str_replace('controllers/', '', $route);
            $route = str_replace('modules/', '', $route);
            $explodeName =  explode('/', $route);
            $apiControllerClassName = $explodeName[count($explodeName) - 1];
            unset($explodeName[count($explodeName) - 1]);
            $nameSpace = join('/', $explodeName);
            $routeName = ltrim($nameSpace, 'app') .'/'. strtolower(substr($apiControllerClassName, 0, -10));

            if (!class_exists($ctlClassName)) {
                continue;
            }
            //  左菜单的标题
            $ref        = new \ReflectionClass($ctlClassName);
            $title      = "//请检测接口服务注释($apiControllerClassName)";
            $desc       = '//请使用@desc 注释';

            $isClassIgnore = false; // 是否屏蔽此接口类
            $docComment = $ref->getDocComment();
            if ($docComment !== false) {
                $docCommentArr = explode("\n", $docComment);
                $comment       = trim($docCommentArr[1]);
                $title         = trim(substr($comment, strpos($comment, '*') + 1));
                foreach ($docCommentArr as $comment) {
                    $pos = stripos($comment, '@desc');
                    if ($pos !== false) {
                        $desc = substr($comment, $pos + 5);
                    }
                    if (stripos($comment, '@ignore') !== false) {
                        $isClassIgnore = true;
                    }
                }
            }

            if ($isClassIgnore) {
                continue;
            }
            $allApiS[$nameSpace][$apiControllerClassName]['title'] = $title;
            $allApiS[$nameSpace][$apiControllerClassName]['desc']  = $desc;
            $allApiS[$nameSpace][$apiControllerClassName]['methods'] = [];

            // 待排除的方法
            $allYiiMethods = get_class_methods('yii\web\Controller');
            $method = array_diff(get_class_methods($ctlClassName), $allYiiMethods);
            sort($method);
            foreach ($method as $mValue) {
                $rMethod = new \Reflectionmethod($ctlClassName, $mValue);
                if (!$rMethod->isPublic() || strpos($mValue, '__') === 0) {
                    continue;
                }
                $mValue = humpToLine($mValue);
                $mValue = str_replace('action-', '', $mValue);


                $title      = '//请检测函数注释';
                $desc       = '//请使用@desc 注释';
                $isMethodIgnore = false;

                $docComment = $rMethod->getDocComment();
                if ($docComment !== false) {
                    $docCommentArr = explode("\n", $docComment);
                    $comment       = trim($docCommentArr[1]);
                    $title         = trim(substr($comment, strpos($comment, '*') + 1));
                    foreach ($docCommentArr as $comment) {
                        $pos = stripos($comment, '@desc');
                        if ($pos !== false) {
                            $desc = substr($comment, $pos + 5);
                        }
                        if (stripos($comment, '@ignore') !== false) {
                            $isMethodIgnore = true;
                        }
                    }
                }

                if ($isMethodIgnore) {
                    continue;
                }
                $routeName .= '/'.$mValue;
                $allApiS[$nameSpace][$apiControllerClassName]['methods'][$routeName] = array(
                    'service' => $routeName,
                    'title'   => $title,
                    'desc'    => $desc,
                );
            }

            //echo json_encode($allApiS) ;
            //字典排列
            ksort($allApiS);
        }

        foreach ($allApiS as $namespace => $subAllApiS) {
            ksort($subAllApiS);
            if (empty($subAllApiS)) {
                unset($allApiS[$namespace]);
                continue;
            }
        }
        return $allApiS;
    }
}

function listDir($dir) {
    $dir .= substr($dir, -1) == D_S ? '' : D_S;
    $dirInfo = array();
    foreach (glob($dir . '*') as $v) {
        if (is_dir($v)) {
            $dirInfo = array_merge($dirInfo, listDir($v));
        } else {
            $dirInfo[] = $v;
        }
    }
    return $dirInfo;
}

/*
 * 下划线转驼峰
 */
function convertUnderline($str)
{
    $str = preg_replace_callback('/([-_]+([a-z]{1}))/i',function($matches){
        return strtoupper($matches[2]);
    },$str);
    return $str;
}

/*
 * 驼峰转下划线
 */
function humpToLine($str){
    $str = preg_replace_callback('/([A-Z]{1})/',function($matches){
        return '-'.strtolower($matches[0]);
    },$str);
    return $str;
}
