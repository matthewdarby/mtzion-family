Preset for Google Calendar
--------------------------

For date parsing via PHx, create a new snippet named: phx:google
And enter the following PHP code:

<?php
if (strpos($output, 'T')) {

	$mystring = str_replace('T', ' ', $output);
	$mystring = substr($mystring, 0,16);
	$dt = strftime($options,strtotime($mystring));
        if ($modx->config['modx_charset'] == 'UTF-8')
	    $dt = utf8_encode($dt);
	return $dt;
}
else {
	$dt = strftime($options,strtotime($output));
        $dt = trim(substr($dt,0,strpos($dt, '@')));
       return $dt;
}
?>

Then change:

<p><b>Starts:</b> [+GD|WHEN.STARTTIME+] <br />
<b>Ends:</b> [+GD|WHEN.ENDTIME+]</p>

To:

<p><b>Starts:</b> [+GD|WHEN.STARTTIME:google=`%B %d, %G @ %T`+] <br />
<b>Ends:</b> [+GD|WHEN.ENDTIME:google=`%B %d, %G @ %T`+]</p>
