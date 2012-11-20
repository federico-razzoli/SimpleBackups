#      dia
1        8    Mier          creo backup; backupsCount <= 7
2        9                  creo backup; backupsCount  <= 7
3       10                  creo backup; backupsCount  <= 7
4       11                  creo backup; backupsCount  <= 7
5       12                  creo backup; backupsCount  <= 7
6       13    Lun  <---     creo backup; backupsCount  <= 7 dow() == Lun;
7       14    Mar           creo backup; backupsCount  <= 7
8       15    Mie           creo backup; backupsCount  > 7; dow() <> Lun; borro
9       16    Jue           creo backup; backupsCount  > 7; dow() <> Lun; borro
10      17    Vie           creo backup; backupsCount  > 7; dow() <> Lun; borro
11      18    Sab           creo backup; backupsCount  > 7; dow() <> Lun; borro
12      19    Dom           creo backup; backupsCount  > 7; dow() <> Lun; borro
13      20    Lun  <---     creo backup; backupsCount  > 7; dow() == Lun; archive(move-previous-week)
14      21    Mar
15      22    Mie






2012-06-09  -> archived removed
2012-06-16  -> archived removed
2012-06-23  -> archived removed

2012-06-30  -> archived
2012-07-07  -> archived; count > 4; [0] not last-in-month; remove
2012-07-14  -> archived; count > 4; [0] not last-in-month; remove
2012-07-21  -> archived; count > 4; [0] not last-in-month; remove
2012-07-28  -> archived; count > 4; [0] is last-in-month; archive