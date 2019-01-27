<?php
require('conf.php');

$pdo->exec('DELETE FROM games WHERE last_activity < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$pdo->exec('DELETE FROM users WHERE last_activity < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

function createGame($name, $user)
{
    global $pdo;
    $cards_sentences_r = $pdo->query("SELECT id FROM cards WHERE sentence='1'")->fetchAll();
    $cards_words_r = $pdo->query("SELECT id FROM cards WHERE sentence='0'")->fetchAll();

    $cards_sentences = [];
    $cards_words = [];
    foreach($cards_words_r as $k => $v){ $cards_words[] = $v['id']; }
    foreach($cards_sentences_r as $k => $v){ $cards_sentences[] = $v['id']; }

    shuffle($cards_sentences);
    shuffle($cards_words);
    $insertion = $pdo->prepare('INSERT INTO games(name, last_activity, cards_words, cards_sentences) VALUES(?,NOW(),?,?)');
    $insertion->execute([$name,
        implode(',', $cards_words),
        implode(',', $cards_sentences)]);

    $game = $pdo->lastInsertId();
    addPlayerToGame($game, $user);
}

function addPlayerToGame($game, $user)
{
    global $pdo;
    $cards = explode(',', $pdo->query('SELECT cards_words FROM games WHERE id = '.intval($game))->fetch()['cards_words']);
    $joueur = [];
    for($i = 0;$i<7;$i++)
    {
        $card = array_shift($cards);
        if($card !== null)
            $joueur[] = $card;
        else
            return false;
    }

    $pdo->prepare('UPDATE games SET cards_words = ? WHERE id = ?')->execute([implode(',', $cards), $game]);
    $pdo->prepare('UPDATE users SET cards = ?, game = ?, card = -1, vote = -1, last_activity = NOW() WHERE id = ?')->execute([implode(',', $joueur), $game, $user]);
    
    return true;

}

$userid = false;
$headers = getallheaders();
if(isset($headers['X-UK']) && !empty($headers['X-UK']))
{
    $req = $pdo->prepare('SELECT * FROM users WHERE userkey = ?');
    $req->execute([$headers['X-UK']]);

    $don = $req->fetch();

    if(!empty($don['id']))
    {
        $pdo->exec('UPDATE users SET last_activity = NOW() WHERE id = '.$don['id']);
        $userid = $don['id'];
        $user = $don;
    }
}


if(isset($_GET['login'])){
    if(isset($_POST['username'])){
        $req = $pdo->prepare('SELECT COUNT(*) AS nb FROM users WHERE username = ?');
        $req->execute([$_POST['username']]);
        $don = $req->fetch();

        if($don['nb'] < 1){
            $userkey = sha1(uniqid().time());
            $req = $pdo->prepare('INSERT INTO users(username, userkey, last_activity) VALUES(?, ?, NOW())');
            $req->execute([$_POST['username'], $userkey]);
            exit(json_encode(['ok' => true, 'userkey' => $userkey]));
        }else{
            exit(json_encode(['ok' => false, 'message' => 'Ce nom d\'utilisateur est déjà pris.']));
        }
    }
}

if(isset($_GET['lobby'])){
    if($userid){
        $req = $pdo->query('SELECT * FROM games ORDER BY last_activity DESC');

        $retour = [];
        while($don = $req->fetch()){
            $retour[] = [
                'id' => $don['id'],
                'name' => $don['name']
            ];
        }

        exit(json_encode(['games' => $retour]));
    }
}

if(isset($_GET['create'])){
    if($userid){
        $name = $_POST['name'];
        $req = $pdo->prepare('SELECT * FROM games WHERE name = ?');
        $req->execute([$name]);

        $don = $req->fetch();
        if(isset($don['id'])){
            exit(json_encode(['ok' => false, 'message' => 'Une partie avec ce nom existe déjà.']));
        }else{
            createGame($name, $userid);
            exit(json_encode(['ok' => true]));
        }
    }
}

if(isset($_GET['join'])){
    if($userid){
        $name = $_POST['name'];
        $req = $pdo->prepare('SELECT * FROM games WHERE name = ?');
        $req->execute([$name]);

        $don = $req->fetch();
        if(isset($don['id'])){
            $result = addPlayerToGame($don['id'], $userid);
            exit(json_encode(['ok' => $result, 'message' => (!$result?"Impossible de rejoindre la partie :\nCelle-ci est pleine ou terminée.":'')]));
        }else{
            createGame($name, $userid);
            exit(json_encode(['ok' => false]));
        }
    }
}

function voteCountAndFinish($players, $updt = false){
    global $pdo;
    $votes = [];
    $plays = [];
    foreach($players as $p){
        if($p['vote'] > 0){
            if(!isset($votes[$p['vote']])) $votes[$p['vote']] = 0;
            $votes[$p['vote']]++;
            $plays[$p['card']] = $p['id'];
        }
    }

    arsort($votes);
    if(count($votes) == 1){
        foreach($votes as $k => $v)
            $winner = $k;
    }elseif(count($votes) > 1){
        $first = null;
        $firstk = null;
        foreach($votes as $k => $v){
            if($first !== null && $v == $first) break;
            elseif($first !== null) $winner = $firstk;
            elseif($first === null) {
                $first = $v;
                $firstk = $k;
            }else
                break;
        }
    }

    if(isset($winner) && $updt)
        $pdo->exec('UPDATE users SET score = score + 1 WHERE id = '.$plays[$winner]);

    if(isset($winner))
        return $winner;
    else
        return false;
}

if(isset($_GET['game']) && $userid){
    $cids = $user['cards'];
    $cards = [];


    $reqgame = $pdo->query('SELECT * FROM games WHERE id = '.$user['game']);
    $dongame = $reqgame->fetch();

    $reqcards = $pdo->query('SELECT * FROM cards WHERE id IN ('.$cids.')');
    while($doncards = $reqcards->fetch()){
        $cards[$doncards['id']] = $doncards['content'];
    }

    $players = [];
    $played = 0;
    $voted = 0;
    $cards_played = [];
    $reqplayers = $pdo->query('SELECT * FROM users WHERE game = '.$dongame['id']);
    while($donplayers = $reqplayers->fetch()){
        $players[$donplayers['id']] = [
            'name' => ($donplayers['username']),
            'score' => $donplayers['score'],
            'played' => $donplayers['card'] != 0,
            'voted' => $donplayers['vote'] != 0,
            'vote' => $donplayers['vote']
        ];
        if($donplayers['card'] != 0){
          $played++;
          if($donplayers['card'] != -1)
            $cards_played[$donplayers['card']] = null;  
        }
        if($donplayers['vote'] != 0){
            $voted++;
        }
    }

    if(count(array_keys($cards_played)) > 0){
        $doncp = $pdo->query('SELECT * FROM cards WHERE id IN ('.implode(',', array_keys($cards_played)).')')->fetchAll();
        foreach($doncp as $d){
            $cards_played[$d['id']] = $d['content'];
        }
    }



    $state = '';
    $userplayed = false;
    $uservoted = $user['vote'];
    $sentence = '';
    if(count($players) < 2) $state = 'waiting';
    elseif($dongame['card'] == 0){
        // Starting a game
        if(empty($dongame['cards_sentences'])){
            $state = 'finished';
        }else{
            $gcards = explode(',', $dongame['cards_sentences']);
            $gcard = array_shift($gcards);
            $dongame['card'] = $gcard;
            $pdo->exec('UPDATE games SET card = '.$gcard.', last_activity = NOW(), cards_sentences = \''.implode(',', $gcards).'\', game_start = NOW() WHERE id = '.$dongame['id']);
            $pdo->exec('UPDATE users SET card = 0, vote = 0 WHERE game = '.$dongame['id']);
        }
    }

    if($dongame['card'] != 0 && count($players) >= 2){
        $state = 'playing';
        $dons = $pdo->query('SELECT * FROM cards WHERE id = '.$dongame['card'])->fetch();
        $sentence = $dons['content'];

        if($played == count($players)){
            $start = strtotime($dongame['game_start']);
            $state = 'voting';
            if($voted == count($players)){
                $state = 'score';
                $winner = voteCountAndFinish($players);
            }
            elseif($user['vote'] != 0){
            }elseif(isset($_POST['card']) && $user['vote']==0){
                $c = $_POST['card'];
                if(isset($cards_played[$c])){
                    $uupdt = $pdo->prepare('UPDATE users SET vote = ?, last_activity = NOW() WHERE id = ?');
                    $uupdt->execute([$c, $userid]);
                    $uservoted = $c;
                    $voted++;
                    if($voted == count($players)){
                        voteCountAndFinish($players, true);
                    }
                }
            }

            if($state == 'voting' && time()-$start >= 15){
                $pdo->exec('UPDATE users SET vote = -1 WHERE vote = 0 AND game = '.$dongame['id']);
                voteCountAndFinish($players, true);
                header('location: ?game'); exit;

            }
        }else{
            $start = strtotime($dongame['game_start']);
            if($user['card'] != 0){
                $userplayed = true;
            }elseif(isset($_POST['card']) && $user['card']==0){
                $c = $_POST['card'];
                if(isset($cards[$c])){
                    unset($cards[$c]);
                    $cids = implode(',', array_keys($cards));
                    $uupdt = $pdo->prepare('UPDATE users SET cards = ?, card = ?, last_activity = NOW() WHERE id = ?');
                    $uupdt->execute([$cids, $c, $userid]);
                    $userplayed = true;
                    $played++;
                    if($played == count($players))
                        $pdo->exec('UPDATE games SET game_start = NOW() WHERE game = '.$dongame['id']);
                }
            }

            if(time()-$start >= 20){
                $pdo->exec('UPDATE users SET card = -1 WHERE card = 0 AND game = '.$dongame['id']);
                $pdo->exec('UPDATE games SET game_start = NOW() WHERE game = '.$dongame['id']);
                header('location: ?game'); exit;
            }
        }
    }


    exit(json_encode([
        'cards' => $cards,
        'sentence' => $sentence,
        'players' => $players,
        'state' => $state,
        'played' => $userplayed,
        'voted' => $uservoted,
        'cards_played' => ($state == 'voting' || $state == 'score') ? $cards_played : []
        ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

echo(json_encode([]));