# ActiveRecord

Библиотека для работы с базой данных, часть фреймворка Elveneek, но может работать независимо от него.


[![.github/workflows/elveneek_pest.yml](https://github.com/elveneek/activerecord/actions/workflows/elveneek_pest.yml/badge.svg?branch=main)](https://github.com/elveneek/activerecord/actions/workflows/elveneek_pest.yml)


# Разработка

Для того, чтобы тесты заработали, в папке tests необходимо создать файл .env с параметрами подключения к базе данных, и базу данных (можно пустую). Тесты будут сами перезаписывать её и наполнять данными.

Для запуска тестов на Windows, запустите

	vendor\bin\pest.bat

На Linux/MacOS

	vendor/bin/pest