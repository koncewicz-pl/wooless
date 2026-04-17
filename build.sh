#!/usr/bin/env bash

docker build -t wooless-app ./docker/app
docker build -t wooless-wordpress-app ./docker/wordpress
