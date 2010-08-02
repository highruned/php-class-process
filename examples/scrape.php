<?php

	require_once("../src/process.php");

	$process_service = new process_service();

	$url_list = array(
		"http://google.com/",
		"http://facebook.com/",
		"http://twitter.com/"
	);

	foreach($url_list as $url)
		$process_service->run(
		function() use($url)
		{
			return file_get_contents($url);
		},
		function($html)
		{
			var_dump($html);
		});

	while(true)
	{
		$process_service->update();

		echo "."; // testing
		usleep(10000);
	}
?>
