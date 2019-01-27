<?php
require('conf.php');

if(isset($_POST['sentence']) || isset($_POST['word'])){
    $sentence = intval(isset($_POST['sentence']));
    $content = (isset($_POST['sentence'])) ? $_POST['sentence'] : $_POST['word'];

    $insert = $pdo->prepare('INSERT INTO cards(content, sentence) VALUES (?,?)');
    $insert->execute([$content, $sentence]);
}

$req = $pdo->query('SELECT * FROM cards ORDER BY id');
$cards = $req->fetchAll();

?>
<meta charset="utf-8">
<style>
div{ float:left; width: 50%; }
</style>

<div>
    <h1>Sentences</h1>
    <form method="post">
        <ul>
            <?php
            foreach($cards as $c){
                if($c['sentence'])
                    echo '<li>'.htmlspecialchars($c['content']).'</li>';
            }
            ?>
            <li>
                <input type="text" name="sentence" /> <input type="submit" />
            </li>
        </ul>
    </form>
</div>

<div>
    <h1>Words</h1>
    <form method="post">
        <ul>
            <?php
            foreach($cards as $c){
                if(!$c['sentence'])
                    echo '<li>'.htmlspecialchars($c['content']).'</li>';
            }
            ?>
            <li>
                <input type="text" name="word" /> <input type="submit" />
            </li>
        </ul>
    </form>
</div>