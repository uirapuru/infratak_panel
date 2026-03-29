# TODO

## OpenTAK integration po provisioningu

1. Po zakończeniu provisioningu przez API OpenTAKServer ma się utworzyć konto z hasłem podanym podczas tworzenia serwera.
- Maksymalnie 10 kont może być utworzonych dla jednego serwera podczas procesu tworzenia.

2. Dane kont mają być zapisane w panelu w bazie danych.
- Hasła muszą być bezpiecznie zaszyfrowane.
- Musi istnieć kontrolowana możliwość odszyfrowania haseł.

3. Po utworzeniu kont system ma pobrać z OpenTAKServer dla każdego konta:
- link do connection data package (ZIP),
- QR code do connection data package,
- informację, do którego konta należą dane.
- QR code i ZIP mają być zapisane w tabeli kont wraz z powiązaniem do konta.
