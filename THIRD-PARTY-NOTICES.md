# Hinweise zu Fremdlizenzen

AirPlay-Steuerung selbst steht unter der PolyForm Noncommercial License 1.0.0 (siehe `LICENSE`).
Es enthält keinen fremden Quellcode. Das folgende Werk unter der MIT-Lizenz diente als Vorlage
und als Prüfreferenz:

## pyatv

Ablauf der AirPlay-2-Kopplung (Transient Pair-Setup, SRP-6a 3072 Bit mit SHA-512), die
Schlüsselableitung, die HAP-Rahmenverschlüsselung, die RTSP-Nachrichten für den Audiostrom
(SETUP, RECORD, FLUSH, SET_PARAMETER, TEARDOWN), Zeit- und Synchronisationspakete sowie die
Paketverschlüsselung des Audiostroms sind nach https://github.com/postlund/pyatv in PHP
nachgebaut. Der Prüfstand vergleicht die SRP-Rechnung mit `srptools`, das pyatv dafür nutzt;
beide Bibliotheken sind nicht Teil des Moduls.

```
The MIT License (MIT)

Copyright (c) 2020 Pierre Ståhl

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```
