<?php 
// sanity checks
if (!isset($_POST['action'])){$_POST['action']="";}
if (!isset($_POST['command'])){$_POST['command']="";}
if (!isset($_POST['changetime'])){$_POST['changetime']="";}
if (!isset($_POST['notes'])){$_POST['notes']="";}

echo "<!DOCTYPE HTM><html><head>";
echo "<link rel='stylesheet' type='text/css' href='style.css'/>";
echo "<script type='text/javascript' src='outage.js'></script>";
echo "</head><body>";
echo "<h1>APM Outage Validator</h1>";

//define database connection
	$dbconn = pg_connect(<Database connection info here>) or die('Could not connect: ' . pg_last_error());

//Define initial SQL query
	 $sql1 = "SELECT * FROM (SELECT DISTINCT ON (execution_plan, outage_start, outage_end) *
			FROM (SELECT outages.execution_plan, outages.outage_start::timestamp without time zone AS outage_start, outages.outage_end::timestamp without time zone AS outage_end, outages.duration,
				(CASE
				WHEN invalid_outages.change IS NULL or invalid_outages.change='validate' THEN 'VALID' 
				WHEN invalid_outages.change='dismiss' or invalid_outages.change='uptime' THEN 'INVALID'
				ELSE 'INVALID'
				END) AS valid,
				invalid_outages.changetime,
				invalid_outages.change
				FROM portal.outages
				LEFT JOIN portal.invalid_outages on(outages.execution_plan=portal.invalid_outages.execution_plan and outages.outage_start=portal.invalid_outages.outage_start)
				where  outages.duration>15 and outages.outage_start >= date_trunc('month'::text, 'now'::text::date::timestamp with time zone) AND outages.outage_end < date_trunc('month'::text, 'now'::text::date + '1 mon'::interval) and changetime IS NULL
				ORDER BY invalid_outages.changetime DESC) as a
			UNION
			SELECT execution_plan, outage_start, outage_end, duration, (CASE
				WHEN change IS NULL or change='validate' THEN 'VALID' 
				WHEN change='dismiss' or change='uptime' THEN 'INVALID'
				ELSE 'INVALID'
				END) AS valid,
				changetime,change
				FROM (
					SELECT DISTINCT ON (execution_plan,outage_start,outage_end) *
					FROM portal.invalid_outages
					where outage_start >= date_trunc('month'::text, 'now'::text::date::timestamp with time zone) AND outage_end < date_trunc('month'::text, 'now'::text::date + '1 mon'::interval)
					ORDER BY execution_plan,outage_start,outage_end,changetime DESC)
					as b) as c
			 ORDER BY outage_start;";

//Connect and execute query to display all outages for the month
	  $result = pg_query($dbconn, $sql1);
	  if (!$result) {
		 die("Error in SQL query: " . pg_last_error());
	  }
	  $resultset=pg_fetch_all($result);
	  //print_r($resultset);

	  //Each outage gets a separate form area
	  if($resultset){
		 foreach ($resultset as $key => $value){
				$row=$value;
				echo "<div class='outage'>";
				echo "<form method='POST' action='invalid.php' name='validate'>";
				echo "<p>".$row['execution_plan']."</p>";
				echo "<p>Start: ".$row['outage_start']."   End: ".$row['outage_end']."   Duration: ".$row['duration']." minutes</p>";
				echo "<input type=hidden name='execution_plan' value='".$row['execution_plan']."'></input>";
				echo "<input type=hidden name='outage_start' value='".$row['outage_start']."'></input>";
				echo "<input type=hidden name='outage_end' value='".$row['outage_end']."'></input>";
				echo "<input type=hidden name='duration' value='".$row['duration']."'></input>";
				echo "<input type=hidden name='change' value='".$row['change']."'></input>";
				if($row['valid']=='VALID'){
					echo "<input type=hidden name='command' value='invalidate'/>";
					echo "<div class='toggle' ><input type='button' onclick='dropdown();' value='Mark not valid'></input>";
						echo "<div class='userfields'>";
						//js to show these form fields if button with class toggle is clicked
						echo "<label>Username:</label><input type='text' name='user' value=''></input>";
						date_default_timezone_set('America/Denver');
						$changetime = date('Y-m-d H:i:s');
						echo "<input type=hidden name='changetime' value='".$changetime."'></input>";
						echo "<label>Description:</label><input type='text' name='notes' value=''></input>";
						echo "<label>Count as:</label><input type='radio' name='change' value='uptime'/><label for='uptime'>Count as uptime</label><input type='radio' name='change' value='dismiss'  checked/><label for='dismiss'>Don't count</label></input>";
						echo "<input type='submit' value='Submit' onclick='javascript:document.validate.submit()' /></div>";
					echo "</div>";
				} else {
					echo "<input type=hidden name='command' value='validate'/>";
					echo "<div class='toggle' ><input type='button' onclick='dropdown();' value='Mark as valid instead'></input>";
						echo "<div class='userfields'>";
						//js to show these form fields if button with class toggle is clicked
						echo "<label>Username:</label><input type='text' name='user' value=''></input>";
						date_default_timezone_set('America/Denver');
						$changetime = date('Y-m-d H:i:s');
						echo "<input type=hidden name='changetime' value='".$changetime."'></input>";
						echo "<label>Description:</label><input type='text' name='notes' value=''></input>";
						echo "<input type=hidden name='command' value='validate'></input>";
						echo "<input type='submit' value='Submit' onclick='javascript:document.validate.submit()' /></div>";
					echo "</div>";
				}
				echo "</form></div>";
			  }
		}
//Submit validation form

//Insert change record in invalid_outages table, and modify error_violations in members_data_rollup table
if (isset($_POST['command']) and $_POST['command']!="") {
	$reps=($_POST['duration'])/5;
	$start = $_POST['outage_start'];
	
	 //Set executionplanid and application
	$sql2="SELECT executionplanid,applicationid 
			FROM portal.execution_plans 
			WHERE name='".$_POST['execution_plan']."'";
	$result2=pg_fetch_all(pg_query($dbconn, $sql2));
	$result2=$resultset2[0];
	if($result2){
	 $member_id = pg_unescape_bytea($result2['executionplanid']);
	 $app_id = pg_unescape_bytea($result2['applicationid']);
	}
	//Define the update data and conditions for members_data_rollup based on whether you are validating or invalidating the outage		
	 if ($_POST['command'] == 'invalidate'){
		echo "<h2>INVALID</h2>";
		if ($invalid_info['change']=='dismiss'){
			$data=array('error_violations'=>2.00, 'tier'=>'INVALID'); //error violations of 2 are hidden from the summarized_results view
		} elseif (($invalid_info['change']=='uptime')) {
			$data=array('error_violations'=>0.00, 'tier'=>'INVALID');
		}
		$condition=array('timestamp'=>$time,'member_id'=>$member_id, 'application'=>$app_id,'error_violations'=>100.00);
	}
	if ($_POST['command'] == 'validate'){
		$data=array('error_violations'=>100.00,'tier'=>'SYNTHETIC');
		$condition=array('timestamp'=>$time,'member_id'=>$member_id, 'application'=>$app_id,'tier'=>'INVALID');
	}	
	
	//Get timestamp of outage start time for first update query
	$sql3="SELECT (floor(EXTRACT(EPOCH FROM (SELECT TIMESTAMP '". $start."' at TIME ZONE 'MST'))) ||to_char((SELECT TIMESTAMP '". $start."' at TIME ZONE 'MST'), 'MS')) as starttime;";
	$result3=pg_fetch_all(pg_query($dbconn, $sql3));
	$result3=$resultset3[0];
	if($resultset3){
		$time = $result3['starttime'];
	}	
	pg_update($dbconn,'portal.members_data_rollup',$data,$condition);
	
	//Repeat the update query for each 5-minute timestamp for the full duration of the outage
	$i=0;
	while ($i<$reps){
		$sql4="SELECT (TIMESTAMP '". $start."' at TIME ZONE 'MST' +'00:05:00'::interval)as next, 
			(floor(EXTRACT(EPOCH FROM (TIMESTAMP '". $start."' at TIME ZONE 'MST' +'00:05:00'::interval))))*1000 as nexttimestamp;";
		$result4=pg_fetch_all(pg_query($dbconn, $sql4));
		$result4=$result4[0];
		if($resultset4){
			$time = $result4['nexttimestamp'];
		}	
		$condition['timestamp']=$time;
		pg_update($dbconn,'portal.members_data_rollup',$data,$condition);
		$i++;
	}
	//Print confirmation for user	
	if ($_POST['command'] == 'invalidate'){ echo "<h2>INVALID</h2>";}
	if ($_POST['command'] == 'validate'){ echo "<h2>VALID</h2>";}
		
	//Submit record of change
	if ($_POST['command'] == 'validate'){
		$_POST['change']='validate';
	}
	unset($_POST['command']);
	unset($_POST['action']);
	$res=pg_insert($dbconn,'portal.invalid_outages',$_POST);
	
	//Refresh page on update/insert 
	if ($res) {
		echo "<script>window.open('invalid.php','_self') </script>"; 
	} else {
		echo "<p>Change not submitted to invalid_outages table. Please investigate.</p>";
	}
}
//close connection
pg_close($dbconn);
echo "</body></html>";
?>
