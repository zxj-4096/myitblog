<?php
class MainController extends BaseController {
	// 首页
	function actionIndex(){
	}
	
	// 接收提交表单
	function actionReceive(){
		// 把提交的数据先dump($_POST)出来看看是良好的习惯。
		
		if(isset($_POST["username"])){
			echo "已经提交了".$_POST["username"];
		}else{
			echo "没有填东东呢";
		}
	}
}
