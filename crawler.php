<?php
    require_once __DIR__ . '/vendor/autoload.php';

    class Job {
        private $url;
        private $timestamp;
        private $id; // id is timestamp + job id
        private $name;
        
        function getUrl() {
            return $this->url;
        }

        function getTimestamp() {
            return $this->timestamp;
        }

        function getId() {
            return $this->id;
        }

        function setUrl($url) {
            $this->url = $url;
        }

        function setTimestamp($timestamp) {
            $this->timestamp = $timestamp;
        }

        function setId($id) {
            $this->id = $id;
        }

        function getName() {
            return $this->name;
        }

        function setName($name) {
            $this->name = $name;
        }
    }

    class Crawler {
        
        private $inputFile;
        private $dom;
        private $jobsFound = array();
        private $cacheFile;
        private $newJobs = array(); //only new jobs when comparing with the old
        private $skipJobsBefore = '- 1 week';
        private $addr = 'https://www.jobs.bg/front_job_search.php?zone_id=0&distance=0&location_sid=2&categories%5B%5D=40&all_type=0&all_position_level=1&all_company_type=1&keywords%5B%5D=%D0%BC%D0%B0%D1%81%D0%B0%D0%B6%D0%B8%D1%81%D1%82&keywords%5B%5D=%D0%BC%D0%B0%D1%81%D0%B0%D0%B6&keywords%5B%5D=%D0%BC%D0%B0%D1%81%D0%B0%D0%B6%D0%B8%D1%81%D1%82%D0%BA%D0%B0&keywords%5B%5D=%D1%82%D0%B5%D1%80%D0%B0%D0%BF%D0%B5%D0%B2%D1%82+%D0%BC%D0%B0%D1%81%D0%B0%D0%B6%D0%B8%D1%81%D1%82&keywords%5B%5D=%D1%82%D0%B5%D1%80%D0%B0%D0%BF%D0%B5%D0%B2%D1%82&keyword=&last=0&email=&subscribe=1';
        
        public function __construct() {
            $this->dom = new DOMDocument();
            $this->inputFile = __DIR__ . '/storage/jobs.html';
            $this->cacheFile = __DIR__ . '/storage/stored.jobs';
        }
        
        public function run() {
            //we download the html in order to crawl it
            $this->downloadHTML();
            
            //load into dom
            @$this->dom->loadHTMLFile($this->inputFile);
            
            // triverse the tree
            $this->crawl();
            
            //filter jobs by cache
            $this->filterNewJobs();
            
            //save jobs
            $sjobs = serialize($this->jobsFound);
            file_put_contents($this->cacheFile, $sjobs);
            
            //send email only for the new jobs found
            $this->sendEmail();
        }
        
        private function downloadHTML() {
            $web = "wget '{$this->addr}' -O {$this->inputFile} &>/dev/null";
            shell_exec($web);
        }
        
        private function crawl() {
            $domList = $this->dom->getElementsByTagName('td');
            foreach ( $domList as $element ) {
                if ( $element->getAttribute('class') == 'offerslistRow' ) {
                    $job = new Job();
                    $skipJob = false;
                    
                    $spans = $element->getElementsByTagName('span');
                    foreach ( $spans as $span ) {
                        //explainGray
                        if ( $span->getAttribute('class') == 'explainGray' ) {
                            $time = $span->textContent;
                            if ( $time == 'днес') {
                                $dto = new DateTime('now');
                            }elseif ($time == 'вчера') {
                                $dto = new DateTime('yesterday');
                            }else {
                                $dto = DateTime::createFromFormat ( 'd.m.y' , $time );
                            }
                            //check if job is not too old
                            $skipTime = strtotime($this->skipJobsBefore);
                            if ( $skipTime > $dto->getTimestamp() ) {
                                $skipJob = true;
                                break;
                            }
                            $ts = $dto->getTimestamp();
                            $job->setTimestamp($ts);
                        }
                    }
                    
                    if ( $skipJob ) continue;
                    
                    $anchors = $element->getElementsByTagName('a');
                    foreach ( $anchors as $anchor ) {
                        if ( $anchor->getAttribute('class') == 'joblink' ) {
                            //check if the job against db
                            $job->setUrl( $element->baseURI . $anchor->getAttribute('href'));
                            $id = substr($anchor->getAttribute('href'), 4);
                            $job->setId($id);
                            $job->setName($anchor->textContent);
                            $this->jobsFound[$job->getId()] = $job;
                        }
                    }
                }
            } 
        }
        
        private function filterNewJobs() {
            //read chache
            if ( !file_exists( $this->cacheFile ) ) {
                $this->newJobs = $this->jobsFound;
                return;
            }
            
            $contents = file_get_contents($this->cacheFile);
            $oldJobs = unserialize($contents);
            if ( empty($oldJobs) ) {
                $this->newJobs = $this->jobsFound;
                return;
            }
            
            foreach ( $this->jobsFound as $id => $job ) {
                if ( !array_key_exists($id, $oldJobs) ) {
                    $this->newJobs[$id] = $job;
                }
            }
        }
        
        private function sendEmail() {
            if ( empty($this->newJobs) ) {
                print_r("No new jobs \n");
                return true;
            }
                
            
            //send email with new jobs
            $email = 'i.n.dobrev@mail.bg';
            $toMail = 'n.lukova92@gmail.com';
            $transport = (new Swift_SmtpTransport('smtp.mail.bg', 465, "ssl"))
                ->setUsername($email)
                ->setPassword('bgvoin');

            // Create the Mailer using your created Transport
            $mailer = new Swift_Mailer($transport);

            // Create a message
            $message = (new Swift_Message('Wonderful Subject'))
                ->setFrom([$email => 'Ivan Dobrev'])
                ->setTo([ $toMail, 'i.nikolaev.d@gmail.com'])
                ->setSubject('Нови обяви за работа от jobs :)');
            foreach ( $this->newJobs as $job ) {
                $body  = "Ново предложение за работа: \n";
                $body .= $job->getName();
                $body .= "\nлинк:" . $job->getUrl();
                $body .= "\nОбичам те :) ";
                
                // Send the message
                $message->setBody($body);
                $responce = $mailer->send($message);
                if ( !$responce ) {
                    print_r ("Something went potato! \n");
                }else { 
                    print_r("SEND! \n");
                }
            }
        }
    }
    
    $crawler = new Crawler();
    $crawler->run();
