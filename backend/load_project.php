<?php
require_once("util.php");
#
# Careful changing the main program section as many of the
# variables are used within the subroutines as well. 
#
$data_root = $DATADIR;
$script_dir = $SCRIPTDIR;
$php = "/usr/bin/php";

$metadata_tbl_name 	= "metadata.tsv";  # also will look for GDC file metadata*.json 
$expr_file_name 	= "reduced_data.csv";
$descr_file_name 	= "run_details.txt";
$corex_datadir_name = "text_files";

$time_start = time();

$mode = (isset($argv[1]) ? $argv[1] : "");
#
# Modes (note, modes other than WEB are legacy and have not been tried for a while)
# <empty> : new install using the $dataset_dir and $dataset settings
# CHK : just check files and exit
# CONT : continue load of project that already exists
# WEB : download/unzip data, and then load (note project has already been created)
#

$DSID = 0;
$GLID = 0;
$CRID = 0;

if ($mode == "WEB")
{
	# Launched from web. 
	# The project shell was created by the web page. 
	$CRID = $argv[2];
	#print "CRID=$CRID\n";
	$pdata = array();
	load_proj_data($pdata,$CRID);
	#print_r($pdata);
	$GLID = $pdata["GLID"];
	$DSID = $pdata["DSID"];
	$dataset = $pdata["lbl"];
	$dataset_dir = "$data_root/$dataset";
	if (!is_dir($dataset_dir))
	{
		die("No directory $dataset_dir\n");
	}
	$dataurl = $pdata["dataurl"];
	update_status($CRID,"DOWNLOAD");
	$retval = 0;
	chdir($dataset_dir);
	$zipfile = "$dataset.zip";
	run_cmd("/usr/bin/wget -O $zipfile '$dataurl' > /dev/null 2>&1",$retval);
	if (!is_file($zipfile))
	{
		die("Did not obtain zip file\n");
	}
	update_status($CRID,"UNZIP");
	run_cmd("/usr/bin/unzip $zipfile  > /dev/null 2>&1",$retval);
	run_cmd("chmod -R 777 *",$retval);
	foreach (glob("*") as $dir)
	{
		if (preg_match("/__MACOSX/",$dir))
		{
			continue;
		}
		if (is_dir($dir))
		{
			$dataset_dir .= "/$dir";
		}
	}
	system("rm $zipfile");
}
else
{
	$dataset = "shrink2_reload"; 
	$dataset_dir = "$data_root/shrink2_reload/shrink2";
}

$expression_file = "";
$descr_file = "";
$metadata_tbl_file = "";
$metadata_json_file = "";
find_file($dataset_dir,$expr_file_name,$expression_file,0,1);
find_file($dataset_dir,$descr_file_name,$descr_file,0,1);

if (!find_file($dataset_dir,$metadata_tbl_name,$metadata_tbl_file,0,0))
{
	$metadata_json_files = glob("$dataset_dir/metadata*.json");
	if (count($metadata_json_files) != 1)
	{
		#die("Can't identify metadata json file!\n");
	}
	else
	{
		$metadata_json_file = $metadata_json_files[0];
	}
	if ($metadata_json_file == "")
	{
		die ("Could not find metadata (either $metadata_tbl_name or json file)");
	}
}

############################################################################
#
# Everything after here should not need editing for a GDC dataset
# UNLESS you are re-running the script after a partial load.
# In this case,  set the DSID,GLID,CRID below,
# and carry on. 
#
$corex_datadir = "";
find_file($dataset_dir,$corex_datadir_name,$corex_datadir,1,1);

$labelfile = "$corex_datadir/labels.txt";
$grpfile = "$corex_datadir/groups.txt";
$clabelfile = "$corex_datadir/cont_labels.txt";
$param_file = "$corex_datadir/parameters.json";
$rdatafile = "$dataset_dir/Rdata.tsv";

if (!preg_match("/^\w+$/",$dataset))
{
	die("Invalid dataset name\n");
}

check_file($grpfile);
check_file($labelfile);
check_file($clabelfile);
check_file($param_file);
check_file($descr_file);
check_file($expression_file);

if ($mode == "CHK")
{
	print "FILES OK\n";
	exit(0);
}

$annodir = "$dataset_dir/stringdb";
if (!is_dir($annodir))
{
	mkdir($annodir);
}

$run_method = "corex";
$expr_type = "fpkm";
$weights_dir = $corex_datadir;
$wtfile_pfx = "$weights_dir/weights_layer";
$mifile_pfx = "$weights_dir/mis_layer";
$wtfile_sfx = ".csv";

if ($mode == "")  # It's supposed to be a fresh install
{
	$st = dbps("select id from clr where lbl=?");
	$st->bind_param("s",$dataset);
	$st->execute();
	if ($st->fetch())
	{
		die("Project $dataset exists\n");
	}
}

update_status($CRID,"GRAPH");
#
# First get the number of levels, and also check that we have both corex files for each
#
for ($numLevels = 0; ; $numLevels++)
{
	$wtfile = $wtfile_pfx.$numLevels.$wtfile_sfx;
	$mifile = $mifile_pfx.$numLevels.$wtfile_sfx;
	if (is_file($wtfile) && is_file($mifile))
	{
		continue;
	}
	break;
}
print "CorEx dataset has $numLevels levels (including gene level)\n";
if (!is_file($labelfile))
{
	die("No corex label file $labelfile!\n");
}

#
# Get the gene names from one of the corex files, and check for weird characters
#
$wtfile0 = $wtfile_pfx."0".$wtfile_sfx;
$fh = fopen($wtfile0,"r");
$line = fgets($fh);
fclose($fh);
$garray = explode(",",trim($line));
array_shift($garray); # first entry is "factor"

$gene_seen = array();
foreach ($garray as $gene)
{
	$gene = trim($gene);	
	if (isset($gene_seen[$gene]))
	{
		print ("WARNING: Duplicate gene name: $gene\n");
	}
	$gene_seen[$gene] = 1;
	if (preg_match('/[^\w\.\-]/',$gene))
	{
		die ("bad gene name:$gene\n");
	}
}
print count($gene_seen)." genes \n";

#
# Get and check the sample names, from the corex labels file
#

$sarray = array();
$fh = fopen($labelfile,"r");
while (($line = fgets($fh)) != null)
{
	$fields = explode(",",$line);	
	$sarray[] = trim($fields[0]);
}
fclose($fh);

$sampsSeen = array();
foreach ($sarray as $samp)
{
	$samp = trim($samp);	
	if (isset($sampsSeen[$samp]))
	{
		print ("WARNING:Duplicate sample name: $samp\n");
	}
	$sampsSeen[$samp] = 1;
	if (preg_match('/[^\w-]/',$samp))
	{
		die ("bad sample name:$samp\n");
	}
}
print count($sampsSeen)." samples \n";

#
# Make the primary table entries for run, dataset, and genelist.
# Theoretically different runs could share a dataset and/or genelist,
# e.g. the two different runs of lung. 
# However, it seems like more trouble that it's worth, so I am not sharing like this so far, 
# hence it was probably not necessary to
# separate out these concepts. 
# However that's how I did it, so each run has these three database IDs
# associated to it. 
#

if ($DSID == 0)
{
	dbq("insert into dset (lbl,expr_type) values('$dataset','$expr_type')" );
	$DSID = dblastid("dset","ID");
	print "new DSID: $DSID\n";
}
if ($GLID == 0)
{
	dbq("insert into glists (descr) values('$dataset')");
	$GLID = dblastid("glists","ID");
	print "new GLID: $GLID\n";
}
if ($CRID == 0)
{
	dbq("insert into clr (lbl,meth,GLID,DSID) values('$dataset','$run_method','$GLID','$DSID')");
	$CRID = dblastid("clr","ID");
	print "new CRID: $CRID\n";
}
dbq("update clr set load_dt=NOW() where id=$CRID"); # note date is not set by web uploader

$json = file_get_contents($param_file);
$run_descr = file_get_contents($descr_file);
$st = dbps("update clr set param=?,descr=? where id=$CRID");
$st->bind_param("ss",$json,$run_descr);
$st->execute();
$st->close();

#
# Make the gene and sample entries. 
# Any duplicates will get just one entry.
# If the gene name is not ENSG, we will assume hugo...
# if it is ENSG, its hugo will be obtained from biomart later
#
print "Begin scan genes/samples\n";

$num_loaded = 0;
foreach ($garray as $gene)
{
	$gene = trim($gene);	
	$hugo = "";
	if (substr($gene,0,4) != "ENSP")
	{
		$hugo = $gene;
	}
	if (!entry_exists2("glist","lbl",$gene,"GLID",$GLID))
	{
		dbq("insert into glist (GLID,lbl,hugo) values($GLID,'$gene','$hugo')");
		$num_loaded++;
	}
}
print "Loaded $num_loaded genes\n";

foreach ($sarray as $samp)
{
	$samp = trim($samp);	
	if (!entry_exists2("samp","lbl",$samp,"DSID",$DSID))
	{
		dbq("insert into samp (lbl,DSID) values('$samp',$DSID)");
		$num_loaded++;
	}
}
print "Loaded $num_loaded new samples\n";

#
# Get gene and sample IDs that were just created
#

$gene2ID = array();
$res = dbq("select lbl, ID from glist where GLID=$GLID");
while ($r = $res->fetch_assoc())
{
	$gene2ID[$r["lbl"]] = $r["ID"];
}
$samp2ID = array();
$res = dbq("select lbl, ID from samp where DSID=$DSID");
while ($r = $res->fetch_assoc())
{
	$samp2ID[$r["lbl"]] = $r["ID"];
}

ksort($samp2ID,SORT_NUMERIC);
ksort($gene2ID,SORT_NUMERIC);

$numSamp = count($samp2ID);
$numGene = count($gene2ID);
print "$numGene gene IDs loaded from DB\n";
print "$numSamp sample IDs loaded from DB\n";

#
# Load the factor graph
#

print "clear out factor entries for CRID=$CRID\n";
dbq("delete from g2c where CRID=$CRID");
dbq("delete from c2c where CRID=$CRID");
dbq("delete from clst where CRID=$CRID");

load_corex_factors();

# 
# Get the group IDs just created (only need lowest level)
#

$grp2ID = array();
$res = dbq("select id,lbl from clst where crid=$CRID and lvl=0");
while ($r = $res->fetch_assoc())
{
	$cid = $r["id"];
	$lbl = $r["lbl"];
	$grp2ID[$lbl] = $cid;
}
$numGrp = count($grp2ID);

#
# Load the discrete and continuous label assignments, and the TC values
#

dbq("delete lbls.* from lbls,clst where lbls.CID=clst.ID and clst.CRID=$CRID");
load_corex_labels();
load_tc_values();

# Get the gene hugo names, descriptions, etc from biomart
run_cmd("php $script_dir/biomart_map_genes.php $dataset $dataset_dir",$retval);
#
# Load survival metadata, if we have it
#
if ($metadata_json_file != "")
{
	update_status($CRID,"SURVIVAL");
	print "Loading metadata from json\n";
	run_cmd("$php $script_dir/load_gdc_metadata.php $dataset $metadata_json_file",$retval);
	run_cmd("$php $script_dir/do_survival.php $dataset $rdatafile",$retval);
}
else if ($metadata_tbl_file != "")
{
	update_status($CRID,"SURVIVAL");
	print "Loading metadata from tsv\n";
	run_cmd("$php $script_dir/load_tsv_metadata.php $dataset $metadata_tbl_file",$retval);
	run_cmd("$php $script_dir/do_survival.php $dataset $rdatafile",$retval);
}
#
# Annotation via StringDB
#
update_status($CRID,"ANNOT");
run_cmd("$php $script_dir/do_stringdb_annot.php $dataset $annodir",$retval);

# 
# Now, expression. This is slow.
#
print("############ Begin load expression matrix ###############\n");
update_status($CRID,"EXPR");
run_cmd("$php $script_dir/load_expr.php $dataset $expression_file",$retval);
update_status($CRID,"READY");

##############################################################################################

function load_gdc_metadata()
{
	global $metadata_json_file, $DSID, $CRID, $GLID, $samp2ID;

	check_file($metadata_json_file);
	$sampstr = file_get_contents($metadata_json_file);
	$sampdt = json_decode($sampstr); 

	dbq("delete sampdt.* from sampdt,samp where samp.id=sampdt.sid ".
				"  and samp.dsid=$DSID ");

	$numSamp = count($samp2ID);

	$data = array();
	$numBC = 0;
	foreach ($sampdt as $dt)
	{
		$numBC++;
		$bc = preg_replace('/\.FPKM.*$/','',$dt->file_name);
		if (!isset($samp2ID[$bc]))
		{
			die("unknown $bc\n");
		}
		$sid = $samp2ID[$bc];

		# get the TCGA name which we will load as an alias
		$ae = array_shift($dt->associated_entities);
		$esi = $ae->entity_submitter_id;
		$surv_info = $dt->cases[0]->diagnoses[0];
		$status = strtolower($surv_info->vital_status);
		$dtd = trim($surv_info->days_to_death);
		$dtlc = trim($surv_info->days_to_last_follow_up);
		$age = trim($surv_info->age_at_diagnosis);

		$statflag = ($status == "alive" ? 1 : 0);
		$censor = ($statflag==1 ? 0 : 1);

		if ($dtd == "")
		{
			$dtd = -1;
		}
		if ($dtlc == "")
		{
			$dtlc = -1;
		}
		if ($age == "")
		{
			$age = 0;
		}

		$dte = ($statflag ? $dtlc : $dtd);
		if ($dte < 0 && $dtd > 0)
		{
			print_r($dt);
			die("oops");
		}

		$gender = strtolower(trim($dt->cases[0]->demographic->gender));
		if ($gender == "female")
		{
			$gender = "F";
		}
		else if ($gender == "male")
		{
			$gender = "M";
		}
		else
		{
			$gender = "U";
		}

		$samp_json = json_encode($dt);

		$data[$sid] = array("stat" => $statflag, "dtd" => $dtd, "dtlc" => $dtlc, "json" => $samp_json,
								"dte" => $dte, "censor" => $censor, "age" => $age, "sex" => $gender, "alias" => $esi);
	}
	if ($numSamp != $numBC)
	{
		print "sample mismatch: $numSamp != $numBC\n";	
	}

	$missing = 0;
	foreach ($samp2ID as $bc => $sid)
	{
		if (!isset($data[$sid]))
		{
			print "missing data for sample $bc (sid=$sid)\n";
			$missing++;
		}
	}
	if ($missing > 0)
	{
		exit(0);
	}

	$st = dbps("insert into sampdt (sid,dtd,dtlc,dte,stat,censor,age,sex,fulldata) values(?,?,?,?,?,?,?,?,?)");
	$st->bind_param("iiiiiiiss",$sid,$dtd,$dtlc,$dte,$stat,$censor,$age,$sex,$json);
	$st2 = dbps("insert into sampalias (SID,lbl,idx) values(?,?,1)");
	$st2->bind_param("is",$sid,$alias);
	foreach ($samp2ID as $bc => $sid)
	{
		$dt = $data[$sid];
		$stat 	= $dt["stat"];
		$dtd 	= $dt["dtd"];
		$dtlc 	= $dt["dtlc"];
		$censor = $dt["censor"];
		$dte 	= $dt["dte"];
		$age 	= $dt["age"];
		$sex 	= $dt["sex"];
		$json 	= $dt["json"];
		$alias 	= $dt["alias"];

		$st->execute();
		$st2->execute();
	}
	$st->close();
	$st2->close();
}

##################################################################################

function load_corex_factors()
{
	global $CRID,$wtfile_pfx,$wtfile_sfx,$mifile_pfx,$mifile_sfx,$numGene,$gene2ID;

	print "##############################################\n";
	print "Loading level 0 weights and MI\n";

	$lvl = 0;
	$wtfile = $wtfile_pfx.$lvl.$wtfile_sfx;
	$mifile = $mifile_pfx.$lvl.$wtfile_sfx;
	if (!is_file($wtfile)) 
	{
		die ("No layer0 weight file ($wtfile)!\n");
	}
	if (!is_file($mifile)) 
	{
		die ("No layer0 MI file ($mifile)!\n");
	}

	$wts = array();
	$mis = array();
	read_matrix($wts,$nRows,$nCols,$wtfile);
	$numFacts = $nRows-1;
	if ($nCols != $numGene + 1)
	{
		die ("wt file has $nCols columns!\n");
	}
	print "$numFacts new factors\n";
	read_matrix($mis,$nRows,$nCols,$mifile);
	if ($nCols != $numGene + 1)
	{
		die ("mi file has $nCols!\n");
	}
	if ($nRows != $numFacts + 1)
	{
		die ("mi file has $nRows rows!\n");
	}

	$col2GID = array();
	for ($c = 1; $c < $nCols; $c++)
	{
		$gene = $wts[0][$c];
		if ($gene != $mis[0][$c])
		{
			die ("mismatch of gene column $c!\n");
		}
		if (isset($gene2ID[$gene]))
		{
			$col2GID[$c] = $gene2ID[$gene];
		}
		else
		{
			die("Unknown gene '$gene'\n");
		}
	}

	#
	# Now load level 0 to the database!
	#

	$cids = array();
	for($f = 0; $f < $numFacts; $f++)
	{
		dbq("insert into clst (lbl,lvl,CRID) values($f,$lvl,$CRID)");
		$CID = dblastid("clst","ID");
		$cids[$lvl][$f] = $CID;	
	}

	$num_nonzero = 0;
	for($f = 0; $f < $numFacts; $f++)
	{
		$row = $f+1;
		#print "DB insert for factor:$f                            \r";
		$CID = $cids[$lvl][$f];
		$inserts = array();
		for ($c = 1; $c < $nCols; $c++)
		{
			$GID = $col2GID[$c];
			if (isset($wts[$row][$c]) && 
				isset($mis[$row][$c] ) )
			{
				$wt = $wts[$row][$c];
				if ($wt > 0)
				{
					$mi = $mis[$row][$c];
					$inserts[] = "($CRID,$CID,$GID,$wt,$mi)";
				}
			}
			else
			{
				die ("missing weight or MI for ($f,$GID)\n");
			}
		}
		$new_inserts = count($inserts);
		$num_nonzero += $new_inserts;
		if ($new_inserts > 0)
		{
			$insert = implode(",",$inserts);
			dbq("insert into g2c (CRID,CID,GID,wt,mi) values$insert", 0);
		}
		else
		{
			print "Warning: all weights zero for factor $f!!\n";
		}
	}
	$num_possible = $numFacts*$numGene;
	$nonzero_pct = floor(100*((float)$num_nonzero)/((float)$num_possible));
	print "level 0: loaded $num_nonzero nonzero ($nonzero_pct%)                                       \n";

	#
	# higher levels!
	#

	$lvl++;
	$wtfile = $wtfile_pfx.$lvl.$wtfile_sfx;
	$mifile = $mifile_pfx.$lvl.$wtfile_sfx;
	$numPrevFacts = $numFacts;
	while (is_file($wtfile) && is_file($mifile))
	{
		print "##############################################\n";
		print "Loading level $lvl weights ($numPrevFacts prior factors)\n";

		$wts = array();
		$mis = array();
		read_matrix($wts,$nRows,$nCols,$wtfile);
		$numFacts = $nRows-1;
		if ($nCols != $numPrevFacts + 1)
		{
			die ("wt file has $nCols columns!\n");
		}
		print "$numFacts new factors\n";
		read_matrix($mis,$nRows,$nCols,$mifile);
		if ($nCols != $numPrevFacts + 1)
		{
			die ("mi file has $nCols cols!\n");
		}
		if ($nRows != $numFacts + 1)
		{
			die ("mi file as $nRows rows!\n");
		}

		for ($f = 0; $f < $numFacts; $f++)
		{
			dbq("insert into clst (lbl,lvl,CRID) values($f,$lvl,$CRID)");
			$CID = dblastid("clst","ID");
			$cids[$lvl][$f] = $CID;	
		}

		$num_nonzero = 0;
		for ($f = 0; $f < $numFacts; $f++)
		{
			$row = $f+1;
			#print "DB insert for factor:$f                            \r";
			$CID_new = $cids[$lvl][$f];
			$inserts = array();
			for ($f0 = 0; $f0 < $numPrevFacts; $f0++)
			{
				$col = $f0+1;
				$CID_old = $cids[$lvl-1][$f0];
				if (isset($wts[$row][$col]) && 
					isset($mis[$row][$col] ) )
				{
					$wt = $wts[$row][$col];
					if ($wt > 0)
					{
						$mi = $mis[$row][$col];
						$inserts[] = "($CRID,$CID_old,$CID_new,$wt,$mi)";
					}
				}
				else
				{
					die ("missing weight or MI for ($f,$CID_old)\n");
				}
			}
			$num_inserts = count($inserts);
			$num_nonzero += $num_inserts;
			if ($num_inserts > 0)
			{
				$insert = implode(",",$inserts);
				dbq("insert into c2c (CRID,CID1,CID2,wt,mi) values$insert");
			}
			else
			{
				print "Warning: factor $f has all zero weights!\n";
			}
		}
		$num_possible = $numFacts * $numPrevFacts;
		$nonzero_pct = floor(100*((float)$num_nonzero)/((float)$num_possible));
		print "level $lvl: loaded $num_nonzero nonzero ($nonzero_pct%)                                       \n";

		$lvl++;
		$wtfile = $wtfile_pfx.$lvl.$wtfile_sfx;
		$mifile = $mifile_pfx.$lvl.$wtfile_sfx;
		$numPrevFacts = $numFacts;
		
	}

}
#############################################################################

function load_tc_values()
{
	global $grp2ID, $grpfile;

	print "Load TC values\n";

	$fh = fopen($grpfile,"r");
	while ( ($line = fgets($fh)) )
	{
		$matches = array();
		if (preg_match("/^Group[^\d]+(\d+)[^\d\-]+([\-\d\.]+)/",$line,$matches))
		{
			$cnum = $matches[1];
			$tc = $matches[2];
			$cid = $grp2ID[$cnum];
			#print "$cid\t$gnum\t$tc\n";
			dbq("update clst set tc='$tc' where id=$cid");
		}
	}
}
#############################################################################

function load_corex_labels()
{
	global $labelfile, $clabelfile, $numSamp, $numGrp, $samp2ID, $grp2ID;

	#
	# Load the discrete and continuous labels
	#
	print "Load discrete and continuous labels\n";

	$labels = array();
	$clabels = array();

	$nRows = 0;
	$nCols = 0;

	read_matrix($labels, $nRows, $nCols, $labelfile,0);
	if ($nRows != $numSamp)
	{
		die ("label file has $nRows rows (expecting $numSamp)!");
	}
	if ($nCols != $numGrp+1)
	{
		die ("label file has $nCols cols (expecting ".($numGrp+1).")!");
	}

	read_matrix($clabels, $nRows, $nCols, $clabelfile,0);
	if ($nRows != $numSamp)
	{
		die ("cont_label file has $nRows rows (expecting $numSamp)!");
	}
	if ($nCols != $numGrp+1)
	{
		die ("cont_label file has $nCols cols (expecting ".($numGrp+1).")!");
	}

	for ($r = 0; $r < $numSamp; $r++)
	{
		$samp1 = $labels[$r][0];
		if (!isset($samp2ID[$samp1]))
		{
			die ("unknown sample $samp1 in label file\n");
		}
		$samp2 = $clabels[$r][0];
		if (!isset($samp2ID[$samp2]))
		{
			die ("unknown sample $samp2 in clabel file\n");
		}
		if ($samp1 != $samp2)
		{
			die ("mismatch samples, labels has $samp1, clabel has $samp2\n");
		}
	}
	for ($g = 0; $g < $numGrp; $g++)
	{
		if (!isset($grp2ID[$g]))
		{
			die ("No ID for group $g\n");
		}
	}

	$N = $numSamp;
	for ($r = 0; $r < $numSamp; $r++)
	{
		$samp = $labels[$r][0];
		$SID = $samp2ID[$samp];
		$vals = array();
		for ($g = 0; $g < $numGrp; $g++)
		{
			$CID = $grp2ID[$g];
			$lbl = $labels[$r][$g + 1];
			$clbl = $clabels[$r][$g + 1];
			$vals[] = "($SID,$CID,$lbl,$clbl,0)";
		}	
		#print "$N\t\t$samp                             \r";
		dbq("insert into lbls (SID,CID,lbl,clbl,risk_strat) values".implode(",",$vals));
		$N--;
	}


}
# look for specified file or directory within directories under $topdir
function find_file($topdir,$name,&$result_path,$is_dir,$die_if_absent)
{
	$dir_iterator = new RecursiveDirectoryIterator($topdir,
			RecursiveDirectoryIterator::SKIP_DOTS
			);
	$iterator = new RecursiveIteratorIterator($dir_iterator,
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
			);

	foreach ($iterator as $file_obj) 
	{
		$filename = $file_obj->getFilename();
		if ($filename == $name)
		{
			if ($is_dir)
			{
				if ($file_obj->isDir())
				{
					$result_path = $file_obj->getPath()."/".$filename;
					return 1;
				}
			}
			else
			{
				if ($file_obj->isFile())
				{
					$result_path = $file_obj->getPath()."/".$filename;
					return 1;
				}
			}
		}

	}
	if ($die_if_absent)
	{
		$type = ($is_dir ? "directory" : "file");
		die("Unable to find required $type : $name");
	}
}
?>

