<?php
/**
 * Годный контент для MCPE - vk.com/doffy_pe
 * Автор плагина - vk.com/yexeed
 */

namespace mytimings;


use mytimings\ex\InternetException;
use mytimings\task\BulkCurlTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\TimingsHandler;
use pocketmine\event\TranslationContainer;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

class MyTimings extends PluginBase
{

    public static $timingStart = 0;

    public function onLoad()
    {
        $this->unregCmd(['timings']);
    }

    public function unregCmd(array $cmds)
    {
        $map = $this->getServer()->getCommandMap();
        foreach ($cmds as $cmd) {
            $class = $map->getCommand($cmd);
            if ($class instanceof Command) {
                $class->setLabel($class . "__disabled");
                $class->unregister($map);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        if (!$sender->hasPermission("pocketmine.command.timings")) {
            return true;
        }
        if (\count($args) !== 1) {
            $sender->sendMessage("Использование: /timings on/off/paste");
            return \true;
        }

        $mode = strtolower($args[0]);
        if ($mode === "on") {
            $sender->getServer()->getPluginManager()->setUseTimings(true);
            TimingsHandler::reload();
            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.enable"));
            return true;
        } elseif ($mode === "off") {
            $sender->getServer()->getPluginManager()->setUseTimings(\false);
            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.disable"));
            return true;
        }
        if(!$sender->getServer()->getPluginManager()->useTimings()){
            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.timingsDisabled"));

            return true;
        }
        $paste = $mode === "paste";
        if ($mode === "reset") {
            TimingsHandler::reload();
            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.reset"));
        } elseif ($mode === "merged" or $mode === "report" or $paste) {
            $timings = "";
            if ($paste) {
                $fileTimings = fopen("php://temp", "r+b");
            } else {
                $index = 0;
                $timingFolder = $sender->getServer()->getDataPath() . "timings/";
                if (!file_exists($timingFolder)) {
                    mkdir($timingFolder, 0777);
                }
                $timings = $timingFolder . "timings.txt";
                while (file_exists($timings)) {
                    $timings = $timingFolder . "timings" . (++$index) . ".txt";
                }
                $fileTimings = fopen($timings, "a+b");
            }
            TimingsHandler::printTimings($fileTimings);
            if ($paste) {
                fseek($fileTimings, 0);
                $data = [
                    "browser" => $agent = $sender->getServer()->getName() . " " . $sender->getServer()->getPocketMineVersion(),
                    "data" => $content = stream_get_contents($fileTimings)
                ];
                fclose($fileTimings);
                $host = $sender->getServer()->getProperty("timings.host", "timings.pmmp.io");
                $sender->getServer()->getScheduler()->scheduleAsyncTask(new class($sender, $host, $agent, $data) extends BulkCurlTask
                {
                    /** @var string */
                    private $host;
                    /** @var string */
                    private $sender;
                    public function __construct(CommandSender $sender, string $host, string $agent, array $data)
                    {
                        parent::__construct([
                            ["page" => "https://$host?upload=true", "extraOpts" => [
                                CURLOPT_HTTPHEADER => [
                                    "User-Agent: $agent",
                                    "Content-Type: application/x-www-form-urlencoded"
                                ],
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => http_build_query($data),
                                CURLOPT_AUTOREFERER => false,
                                CURLOPT_FOLLOWLOCATION => false
                            ]]
                        ]);
                        $this->host = $host;
                        $this->sender = ($sender instanceof ConsoleCommandSender) ? "console" : mb_strtolower($sender->getName());
                    }

                    public function onCompletion(Server $server)
                    {
                        $sender = ($this->sender === "console") ? new ConsoleCommandSender() : $server->getPlayerExact($this->sender);
                        if ($sender instanceof Player and !$sender->isOnline()) {
                            return;
                        }
                        $result = $this->getResult()[0];
                        if ($result instanceof InternetException) {
                            $sender->getServer()->getLogger()->logException($result);
                            return;
                        }
                        if (isset($result[0]) && is_array($response = json_decode($result[0], true)) && isset($response["id"])) {
                            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.timingsRead",
                                ["https://" . $this->host . "/?id=" . $response["id"]]));
                        } else {
                            $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.pasteError"));
                        }
                    }
                });
            } else {
                fclose($fileTimings);
                $sender->sendMessage(new TranslationContainer("pocketmine.command.timings.timingsWrite", [$timings]));
            }
        }
        return true;
    }
}