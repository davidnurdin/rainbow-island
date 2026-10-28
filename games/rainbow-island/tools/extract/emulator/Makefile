# The two native tools of the extraction: a small Amstrad CPC 6128 and an AY-3-8912 renderer.
CFLAGS = -O2 -Wall

all: cpc ay

cpc: cpc.c z80.c z80.h fdc.inc
	$(CC) $(CFLAGS) -o cpc cpc.c z80.c

ay: ay.c
	$(CC) $(CFLAGS) -o ay ay.c -lm

clean:
	rm -f cpc ay
