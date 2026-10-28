import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from dsk import load
# CPC hardware colour number -> RGB
HW=[(128,128,128),(128,128,128),(0,255,128),(255,255,128),(0,0,128),(255,0,128),(0,128,128),(255,128,128),
(255,0,128),(255,255,128),(255,255,0),(255,255,255),(255,0,0),(255,0,255),(255,128,0),(255,128,255),
(0,0,128),(0,255,128),(0,255,0),(0,255,255),(0,0,0),(0,0,255),(0,128,0),(0,128,255),
(128,0,128),(128,255,128),(128,255,0),(128,255,255),(128,0,0),(128,0,255),(128,128,0),(128,128,255)]
class Disk:
    def __init__(s,path):
        t=load(path); s.t=t
    def sector(s,track,idx):
        for x in s.t[(track,0)]:
            if x['R']==0x41+idx: return x['data']
    def read(s,track,idx,count):
        out=b''
        for _ in range(count):
            out+=s.sector(track,idx); idx+=1
            if idx==9: idx=0; track+=1
        return out
def mode0_pixels(b):
    # returns 2 pens for a mode 0 byte
    p0=((b>>7)&1)|((b>>2)&2)|((b>>3)&4)|((b<<2)&8)
    p1=((b>>6)&1)|((b>>1)&2)|((b>>2)&4)|((b<<3)&8)
    return p0,p1
def mode1_pixels(b):
    return [((b>>(7-i))&1)|(((b>>(3-i))&1)<<1) for i in range(4)]
def screen_image(mem, base=0xc000, pal=None, width=80, height=200, mode=0, r12=None):
    from PIL import Image
    pxw = width*2 if mode==0 else width*4
    img=Image.new('RGB',(pxw*(1 if mode==1 else 2)//(1 if mode==1 else 1), height))
    img=Image.new('RGB',(width*(2 if mode==0 else 4), height))
    px=img.load()
    for y in range(height):
        addr=base+(y//8)*width+(y%8)*0x800
        for x in range(width):
            b=mem[(addr+x)&0xffff] if base>=0xc000 else mem[(base&0xc000)|((addr+x-(base&0xc000))&0x3fff)]
            if mode==0:
                for i,p in enumerate(mode0_pixels(b)): px[x*2+i,y]=HW[pal[p]&0x1f]
            else:
                for i,p in enumerate(mode1_pixels(b)): px[x*4+i,y]=HW[pal[p]&0x1f]
    return img
