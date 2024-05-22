# Setup system service

DIR=`pwd`

sudo mkdir /usr/local/sstpmultipler
sudo touch /usr/local/sstpmultipler/SSTPMultiplexer.php
sudo chmod 0777 /usr/local/sstpmultipler/SSTPMultiplexer.php

PHP=$(which php)

echo "#!$PHP" | cat - $DIR/SSTPMultiplexer.php > /usr/local/sstpmultipler/SSTPMultiplexer.php

sudo mkdir /usr/local/sstpmultipler/misc
sudo cp $DIR/misc/example_cert.pem /usr/local/sstpmultipler/misc/example_cert.pem
sudo cp $DIR/misc/example_key.pem /usr/local/sstpmultipler/misc/example_key.pem
sudo chown root:root /usr/local/sstpmultipler -R
sudo chmod 0744 /usr/local/sstpmultipler/SSTPMultiplexer.php

sudo mkdir /etc/sstpmultipler
sudo cp $DIR/config.ini /etc/sstpmultipler/config.ini
sudo chmod 664 /etc/sstpmultipler/config.ini
sudo chown root:root /etc/sstpmultipler -R

sudo cp $DIR/sstp-multiplexer.service /etc/systemd/system/sstp-multiplexer.service

sudo systemctl daemon-reload
sudo systemctl enable sstp-multiplexer
sudo systemctl start sstp-multiplexer
