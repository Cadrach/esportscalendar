<?php

echo '<pre>';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'connect.php';

$urlExternal = "http://www.lolesports.com/en_US/";
$urlMatch = "http://api.lolesports.com/api/v2/highlanderMatchDetails?tournamentId=%s&matchId=%s";
$urlLeague = "http://api.lolesports.com/api/v1/leagues/%s";
$urlSchedule = "http://api.lolesports.com/api/v1/scheduleItems?leagueId=%s";

//Google API Client
$client = getClient();
$client->setUseBatch(true);
$service = new Google_Service_Calendar($client);

function getJson($filename, $url){
    set_time_limit(300);

    $file = __DIR__ . "/json/$filename.json";
    if(file_exists($file)){
        $fileContent = file_get_contents($file);
    }
    else{
        $fileContent = file_get_contents($url);
        file_put_contents($file, $fileContent);
    }
    return json_decode($fileContent, true);
}

function getTeam($position, $match, $tournament, $matches, $teams){
    $input = $match['input'][$position-1];
    if(isset($input['roster'])){
        return $teams[$tournament['rosters'][$input['roster']]['team']];
    }
    else if(isset($input['match'])){
        return [
            'acronym' => "Winner of {$matches[$input['match']]['name']}",
            'name' => "Winner of {$matches[$input['match']]['name']}"
        ];
    }
//    else if(isset($input['breakpoint'])){
//        return [
//            'name' => $tournament['breakpoints'][$input['breakpoint']]['name'],
//        ];
//    }
    else{
        return ['name' => '???', 'acronym' => '???'];
    }
}

//Run for all Leagues
$idLeagueStart = 2;
$idLeagueEnd = 2;
for($idLeague=$idLeagueStart; $idLeague<=$idLeagueEnd; $idLeague++){
    //Get files
    $dataLeague = getJson("league$idLeague", sprintf($urlLeague, $idLeague));
    $dataSchedule = getJson("schedule$idLeague", sprintf($urlSchedule, $idLeague));

    //Prepare data into arrays
    $league = $dataLeague['leagues'][0];
    $teams = collect($dataSchedule['teams'])->keyBy('id')->toArray();
    $tournaments = collect($dataSchedule['highlanderTournaments'])->keyBy('id')->toArray();
    $brackets = [];
    $matches = [];
    foreach($tournaments as $t){
        foreach($t['brackets'] as $b){
            $brackets[$b['id']] = $b;
            foreach($b['matches'] as $m){
                $matches[$m['id']] = $m;
            }
        }
    }

    //League short name
    $tournamentShort = explode('-', strtoupper($league['slug']));
    $tournamentShort = isset($tournamentShort[1]) && in_array($tournamentShort[1], ['CS', 'LCS']) ? implode(' ', $tournamentShort) : $tournamentShort[0];

//Read matches
    $events = [];
    foreach($dataSchedule['scheduleItems'] as $scheduleItem){

        if(!isset($scheduleItem['match'])){
            //ignore non matches
            continue;
        }

        //Read data
        $tournament = $tournaments[$scheduleItem['tournament']];
        $bracket = $brackets[$scheduleItem['bracket']];
        $match = $matches[$scheduleItem['match']];
        $tags = $scheduleItem['tags'];
        $team1 = getTeam(1, $match, $tournament, $matches, $teams);
        $team2 = getTeam(2, $match, $tournament, $matches, $teams);
        $dateStart = (new \Carbon\Carbon($scheduleItem['scheduledTime']))->toAtomString();
        $dateEnd = (new \Carbon\Carbon($scheduleItem['scheduledTime']))->addHours(1)->toAtomString();
        $uid = str_replace('-','v', $match['id']);

        //If the match is at most a month old, we will no longer update it
        if((new \Carbon\Carbon($dateStart))->addMonth()->isPast()){
            continue;
        }

        //
        if(count($match['input']) != 2) {
            throw new \Exception('Wrong number of teams for match');
        }

        //Block label
        $labelPrefix = '';
        if( isset($scheduleItem['tags']['blockLabel']) && ! is_numeric($scheduleItem['tags']['blockLabel'])){
            $labelPrefix = ucwords($scheduleItem['tags']['blockLabel'] . ' ');
        }

        //If our event already exists
        if(isset($events[$uid])){
            $uid.= 'vvv' . (new \Carbon\Carbon($scheduleItem['scheduledTime']))->format('Ymdhis');
        }

        //
        $videos = [];
        $dataMatch = getJson('match' . $match['id'], sprintf($urlMatch, $tournament['id'], $match['id']));
        if(count($dataMatch['videos'])){
            $games = $match['games'];
            foreach($dataMatch['videos'] as $video){
                $videos[] = "<a href='{$video['source']}'>{$games[$video['game']]['name']} ({$video['locale']})</a>";
            }
//            print_r($dataMatch['videos']);
        }

        //Work on Summary
        $description = implode('<br/>', [
            $tournament['description'],
            @ucwords(trim("{$tags['blockPrefix']} {$tags['blockLabel']}")),// {$tags['subBlockPrefix']} {$tags['subBlockLabel']}")),
            "{$team1['name']} vs {$team2['name']}",
            count($videos) ? '<br/><b>VODS:</b><br/>' . implode('<br/>', $videos) : '',
        ]);


//        print_r($team1);
//        $url = $tournament['langu']

        $events[$uid] = new Google_Service_Calendar_Event(array(
            'id' => $uid,
            'summary' => "[$tournamentShort] $labelPrefix{$team1['acronym']} vs {$team2['acronym']}",
            'description' => $description,
            'start' => array(
                'dateTime' => $dateStart,
            ),
            'end' => array(
                'dateTime' => $dateEnd,
            ),

        ));



        //Summary line for debug
        echo "{$league['id']} - {$events[$uid]['start']['dateTime']}: {$events[$uid]['summary']} \t\t\t$uid\n$description\n";
    }

    //Uncomment to prevent updating calendar
//    continue;

//Retrieve list of existing Events
    $existingEvents = [];
    $batch = new Google_Http_Batch($client);
    foreach($events as $e){
        $batch->add($service->events->get(CALENDAR_ID, $e['id']));
    }
    foreach($batch->execute() as $e){
        if($e instanceof Google_Service_Calendar_Event){
            $existingEvents[] = $e->id;
        }
    }

//Batch insert / update
    $batch = new Google_Http_Batch($client);
    foreach($events as $event){
        if(in_array($event['id'], $existingEvents)){
            $batch->add($service->events->update(CALENDAR_ID, $event['id'], $event));
        }
        else {
            $batch->add($service->events->insert(CALENDAR_ID, $event));
        }
    }

    foreach($batch->execute() as $line){
        if($line instanceof Google_Service_Exception){
            echo $line->getMessage() . "\n";
            if($line->getCode() == 403){
                die();
            }
        }
    }

    sleep(5);
}


//print_r(array_keys($calEvents));
//print_r($calEvents);